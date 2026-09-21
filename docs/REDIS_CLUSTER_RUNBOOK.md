# Redis Cluster — Runbook and Defense Notes

**Add-on A** (see `docs/TASKS.md`, *Add-ons and operations*). Written 2026-09-21
against the running development stack. Every figure below was measured on that
stack, on one Windows laptop running Docker Desktop. Where a number depends on
that machine, it says so.

---

## 1. What it is

A cache in front of MySQL, run as a **Redis Cluster of six nodes: three
primaries, each with one replica**. If a primary stops, its replica takes over.

| | |
|---|---|
| Nodes | `redis-1` … `redis-6` in `compose.yaml` |
| Addresses | fixed, `172.28.200.11` … `.16`, on their own Docker network `redis` |
| Starting layout | primaries `redis-1/2/3`, replicas `redis-4/5/6` |
| Persistence | **none** (no AOF, no snapshots): a node that restarts comes back empty |
| Store of record | **MySQL, always.** Redis holds copies only. |
| Formed by | `redis-cluster-init`, a one-shot container that runs on every `up` and does nothing if the cluster already exists |
| Production | **not deployed.** `compose.prod.yaml` has no Redis and uses the database cache. See §9. |

**What is cached** (everything else reads MySQL directly):

| Read | How long a copy lives | Retired early by a write to |
|---|---|---|
| Reports dashboard (`/api/reports/overview`) | 2 minutes | employees, attendance, audit log, crews, payroll, leave, overtime, holidays, sites |
| Roles, sites, a year's holidays | 10 minutes | the list itself |
| Employee registry, per filter set | 1 minute | employees, sites |
| Attendance recovery queue | 1 minute | attendance, audit log, crews |

**How a read flows.** The cache store is `failover`: Redis first, the MySQL
`cache` table when Redis cannot answer. The reads above go through
`App\Support\ResilientCache`, which computes straight from MySQL whenever the
cache is out. A circuit breaker sits over both: once Redis is found down, nothing
tries it again until a cooldown ends (§5).

---

## 2. What it guarantees, and what it does not

**It guarantees three things.**

1. **Losing one node loses nothing.** A stopped primary's replica is promoted
   about **9 seconds** later. Its copies survive, and the API keeps answering
   throughout with no restart.
2. **Losing the whole cluster costs speed, never availability.** Every page
   keeps working from MySQL. Only the first request pays to find the cluster
   down; the rest answer at normal speed.
3. **It is never wrong for longer than it has to be.** An edit made while the
   cache is out is honoured on the first read after it comes back, not when the
   old copy expires.

**It does not:**

- **survive the machine.** All six nodes run on one host. One dead machine is
  still a dead cache (and a dead API, and a dead MySQL). This is a demonstration
  of the mechanism, not production high availability.
- **keep Redis serving during a failover.** Between a primary stopping and its
  replica being promoted (~9 s), queries fail: at first those for the stopped
  primary's keys, then **every** query once the cluster declares itself down
  (`CLUSTERDOWN`). The first failure trips the breaker, which keeps Redis out
  for 15 s. That is set deliberately longer than a promotion, so the breaker
  trips once, not twice. **Losing one primary therefore means about 15–20
  seconds of reads served from MySQL** (measured: 18 s), with no errors, then
  back on Redis.
- **fail back.** Once the stopped node returns, it rejoins as a *replica* of the
  node that replaced it. The original layout is restored only on request (§4, step 5).
- **make this laptop fast.** Every request here carries roughly 150–650 ms of
  framework start-up over the Windows bind mount, depending on load, even one
  that never touches the cache. On a machine that busy, a cached read and a computed one can look
  alike. The reliable sign of a cache hit is an unchanged `generated_at`, not
  the timing.

---

## 3. Everyday checks

```bash
docker compose exec redis-1 redis-cli cluster info
```
Look for `cluster_state:ok` and `cluster_known_nodes:6`.

```bash
docker compose exec redis-1 redis-cli --cluster check redis-1:6379
```
Expect `[OK] All nodes agree about slots configuration.` and
`[OK] All 16384 slots covered.`

```bash
docker compose exec redis-1 redis-cli cluster nodes
```
Shows who is a primary (`master`) and who is a replica (`slave`). **Every line
must carry an address.** A line reading `:0@0 … noaddr` means the cluster has
lost track of a node (§8).

**Is the cache really Redis?** It once was not, for a whole day, while every
page and every test still passed (§8). After opening any cached page:

```bash
docker compose exec redis-1 redis-cli --scan
docker compose exec redis-2 redis-cli --scan
docker compose exec redis-3 redis-cli --scan
```
Keys named `…hris:reports:…`, `…hris:reference:…` should appear across the
primaries. And the MySQL fallback should stay empty while Redis is healthy:

```bash
docker compose exec api php artisan tinker --execute="echo DB::table('cache')->count();"
```

**Is the breaker open?**

```bash
docker compose exec api cat storage/framework/cache/cache-degraded
```
No such file means Redis is in use. Otherwise it reads, for example,
`{"failures":2,"until":1789967249,"pending":["employees"]}`: the second failure
in a row, Redis skipped until that Unix time, and the `employees` reads owed a
retirement when it returns.

---

## 4. Drill 1 — lose one primary

1. Open the dashboard (`/reports`) and note **Generated at**. That copy is now
   in Redis.
2. Find which primary holds it:
   ```bash
   docker compose exec redis-1 redis-cli --scan --pattern '*overview*'
   ```
   Repeat on `redis-2` and `redis-3`; one of them answers.
3. Stop that primary, e.g.
   ```bash
   docker compose stop redis-2
   ```
4. Watch its replica take over. Run this a few times:
   ```bash
   docker compose exec redis-6 redis-cli info replication
   ```
   `role:slave` becomes `role:master`.

   **Measured:** promoted **9.2 s** after the stop; `cluster_state:ok` at about
   11 s. That is the 5 s `--cluster-node-timeout` plus failure detection and the
   replica's election.

   **Meanwhile the API, read once a few seconds throughout:** no errors at any
   point. The first read after the stop took 3.5 s and tripped the breaker.
   The log shows exactly one line for it:
   ```
   Cache unavailable; serving from the database. {"exception":"RedisClusterException","message":"Timed out attempting to find data in the correct node!","consecutive_failures":1,"cooldown_seconds":15}
   ```
   (If the first read lands a few seconds later, the message is `The Redis
   Cluster is down (CLUSTERDOWN)` instead. Same meaning.) For the next 15 s,
   reads were computed from MySQL, at 0.5–1.2 s each. Then, 18 s after the
   stop, the log read `Cache answering again.`, and the dashboard came from
   Redis once more. It returned the **original copy from before the stop**
   (unchanged `generated_at`), now held by the promoted replica.

   In that run, cached reads took about 1.2 s instead of 0.5 s for as long as
   the stopped node stayed down. With only a *replica* down, reads stay
   normal, except that some requests take 0.5 s longer when the client tries
   the dead node's address first.

   (`docker compose logs api` shows the log, or read
   `backend/storage/logs/laravel.log`.)
5. Bring the stopped node back:
   ```bash
   docker compose start redis-2
   ```
   It rejoins as a **replica** of the node that replaced it. To put the original
   layout back, promote it again on purpose. This is graceful and loses nothing
   (measured: under 3 s, all keys kept):
   ```bash
   docker compose exec redis-2 redis-cli cluster failover
   ```

---

## 5. Drill 2 — lose the whole cluster

1. Stop all six:
   ```bash
   docker compose stop redis-1 redis-2 redis-3 redis-4 redis-5 redis-6
   ```
2. Use the portal normally: sign in, open the dashboard, the employee list, the
   site filter on *Overrides & Audit*.

**Measured:**

| | Before the breaker (for comparison) | Now |
|---|---|---|
| First request after the cluster died | 9.0 s | 4.1–4.7 s, once |
| Every request after that | 5.6–8.4 s, **indefinitely** | dashboard 0.37–0.94 s, sites 0.33–0.84 s |
| Sign-in | — | 0.9–1.6 s (most of that is password hashing, by design) |

Every answer is correct: HTTP 200, the same lists. The log shows **one line per
cooldown**, not one per request:

```
Cache unavailable; serving from the database. {"exception":"RedisClusterException","message":"Couldn't map cluster keyspace using any provided seed","consecutive_failures":1,"cooldown_seconds":15}
```

While Redis stays down, the cooldown grows: **15 s, 30 s, 1 min, 2 min, then
5 min**. One request per cooldown pays about 4.5 s to check again, and every
other request goes straight to MySQL. All API processes share the cooldown
through the marker file (§3).

---

## 6. Drill 3 — bring it back

```bash
docker compose start redis-1 redis-2 redis-3 redis-4 redis-5 redis-6
```

**Measured:** `cluster_state:ok` **4–8 s** after starting (two runs), every node
at its fixed address, no `noaddr`. The cluster comes back **empty** (`0 keys`): with no
persistence, nothing stale can survive a full outage. The roles may come back
shuffled; §4 step 5 restores them.

The API returns to Redis without a restart. The first request after the
current cooldown finds Redis answering, logs `Cache answering again.`, removes
the marker and refills the cache. Measured: 0.9–1.3 s for that first request,
then reads served from Redis (same `generated_at`).

---

## 7. Drill 4 — an edit made during an outage

This shows guarantee 3 (§2).

1. Open the employee list, so it is cached.
2. Stop one primary (§4).
3. While it fails over, edit an employee's name in the portal.
4. The marker now owes a retirement:
   `{"failures":1,…,"pending":["employees","reports"]}`.
5. Wait about 25 s for the promotion and the cooldown, then reload the list.

**Measured:** the new name appeared on the **first** read after recovery, and
the log read `Cache answering again. {"retired":["employees","reports"]}`.
Before this was fixed, the list would have shown the old name until its copy
expired: up to 1 minute for the registry, 10 for the reference lists.

---

## 8. Troubleshooting

**`cluster nodes` shows `noaddr`, or a replica points at itself.** The fixed
addresses exist to prevent this. Before them, Docker gave the nodes new
addresses on every start while each node remembered the old ones: after one
recreate, `redis-5` was handed `redis-1`'s old address and believed it was its
own primary. `cluster_state` still read `ok`, so it looked healthy while two of
three primaries had a replica that could not be promoted. If it happens again,
reintroduce the node from a healthy one:

```bash
docker compose exec redis-1 redis-cli cluster meet 172.28.200.15 6379
```

or rebuild (next item).

**Rebuild the cluster from nothing.** This is safe at any time, because the
cache holds nothing that MySQL does not:

```bash
docker compose rm -sfv redis-1 redis-2 redis-3 redis-4 redis-5 redis-6 redis-cluster-init
docker compose up -d
docker compose logs redis-cluster-init
```

The last command should end with `[OK] All 16384 slots covered.` The `-v` matters:
it removes the nodes' stored topology, so they form a fresh cluster rather than
trying to resume the old one.

**`docker compose up` fails with a network or address error.** Another Docker
network on the machine overlaps `172.28.200.0/24`. In `compose.yaml`, change the
subnet under `networks: redis:`, the six `ipv4_address` values, and
`REDIS_CLUSTER_NODES`, all together. Then rebuild as above.

**The breaker is open and you want Redis tried now,** not at the end of the
cooldown:

```bash
docker compose exec api rm -f storage/framework/cache/cache-degraded
```

Owed retirements are lost with the file. They are still bounded by each copy's
lifetime (§1), and a `php artisan cache:clear` removes the copies outright.

**A page shows `{}` where a list should be** (in the browser: `x.map is not a
function`). Something cached a PHP object. The cache never rebuilds objects
(`serializable_classes` is `false` in `config/cache.php`, so a leaked `APP_KEY`
cannot be turned into code execution), and they come back broken. The cache now
refuses objects when they are written: look for `Refusing to cache …` in the
log, and convert the value with `->toArray()` or `->all()` before caching it.
This happened once, to the site filter.

**The cache seems to do nothing: Redis stays empty and MySQL's `cache` table
fills up.** Check the failover chain:

```bash
docker compose exec api php artisan tinker --execute="echo json_encode(config('cache.stores.failover.stores'));"
```

With Redis healthy it must read `["redis","database"]`. It read
`["database","array"]` for a day: `config/cache.php` had a second `failover`
entry from the Laravel skeleton, and in PHP a repeated array key silently
replaces the first. `CacheConfigTest` now guards this. Reading `["database"]`
is correct while the breaker is open.

---

## 9. For the defense

**What to demonstrate:** Drill 1 live, which takes about a minute. Stop the
primary holding the dashboard and keep clicking through the portal while its
replica is promoted. Then Drill 2 if there is time: stop everything, show the
portal still works, start it again.

**What to claim, in these words or close to them:**

- "Redis is a cache. MySQL holds every record. The worst a cache failure can do
  is make pages slower."
- "One node down: its replica takes over in about nine seconds, and nothing is
  lost. The whole cluster down: the system keeps running from MySQL, and only
  the first request notices."
- "Before trusting it, we ran it to failure. The drills found four faults that
  every test and every page had missed, because the database fallback gives the
  same answers, only slower."
  1. The cache never used Redis at all (a duplicated configuration key).
  2. A dead cluster made every page take 6–9 s, indefinitely.
  3. Restarts silently removed the failover.
  4. An edit made during an outage could be missing from the lists for up to
     10 minutes.

  Each is fixed and has a test.

**What not to claim:**

- Not **production high availability**: all six nodes share one machine.
- Not **zero downtime for the cache**: a failover means about 15–20 s of reads
  from MySQL (§2). That is slower, not broken.
- Not the **laptop's timings as the system's speed** (§2, last point).
- Not **deployed**: production runs without Redis today (next paragraph).

**Likely questions:**

- *Why not just use MySQL?* We do, for everything that matters. The cache takes
  repeated reads (the dashboard, the lists behind every filter) off the
  database. Since a wrong cache is worse than none, every copy expires on its
  own and every write retires the copies it affects.
- *What if Redis and MySQL disagree?* MySQL wins, always. A copy lives at most
  2 minutes (dashboard) or 10 (reference lists), and a write retires it at
  once, including a write made while Redis was down.
- *Why six nodes?* It is the smallest cluster in which every primary has a
  replica: three primaries to share the data, three replicas to take over.
- *Why is it not in production?* That decision is still open with the team:
  add the cluster, run a single node, or state that clustering is
  development-only. Until then, production uses the database cache, which is
  correct but does not demonstrate this add-on.

---

## 10. Measured figures (2026-09-21, development stack)

| What | Result |
|---|---|
| Primary stopped → replica promoted | 9.2 s (`cluster_state:ok` ≈ 11 s) |
| Primary stopped → reads served from MySQL | 18 s, one breaker trip, no errors; the pre-stop copy survived |
| Stopped node restarted | rejoins as a replica; no automatic failback |
| Manual failback (`cluster failover`) | < 3 s, no keys lost |
| Whole cluster stopped: first request | 4.1–4.7 s |
| Whole cluster stopped: later requests | 0.33–0.94 s (was 5.6–8.4 s before the breaker) |
| Whole cluster stopped: sign-in | 0.9–1.6 s |
| Whole cluster restarted → `cluster_state:ok` | 4–8 s, empty, fixed addresses kept |
| All six force-recreated at once | topology intact, no `noaddr` |
| A dead seed, by name vs by address | 8 s vs 0.5 s to fail |
| Fresh cluster connection per request | 2–6 ms |
| An edit during a failover | shown on the first read after recovery |
