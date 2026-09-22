# Deploying the HRIS on the Server

Step by step, from an empty Ubuntu machine to a running HRIS that people can
sign in to: the portal, the API, MySQL, the Redis cache cluster and nightly
backups. Written 2026-09-22 for the old-PC server (about 7 GB RAM, one spinning
disk). Every command was checked against `compose.prod.yaml` as committed; the
stack itself was built and exercised the same day on the development machine
(§14).

Run the commands in order. Each section says what you should see. If you see
something else, stop and look it up in §12 before going on.

---

## 0. What you end up with

| Container | What it does | Reachable from |
|---|---|---|
| `web` | nginx: serves the portal, passes `/api` to PHP | **port 80**, the only published port |
| `api` | Laravel on php-fpm; runs migrations when it starts | `web` only |
| `scheduler` | ends expired acting-foreman covers, prunes old sign-in tokens | nothing |
| `mysql` | MySQL 8.0, the store of record | `api`, `scheduler` |
| `redis-1` … `redis-6` | the cache: three primaries, each with one replica | `api`, `scheduler`, on an internal network |
| `redis-cluster-init` | forms the cluster once, then exits | — |
| `tunnel` | optional Cloudflare quick tunnel (§8) | — |

Everything that matters lives in two Docker volumes: `hris_mysql-data` (the
database) and `hris_api-storage` (Laravel's storage). Redis holds only copies;
if it is lost, nothing is lost (`docs/REDIS_CLUSTER_RUNBOOK.md`).

**You need:**

- The server with **Ubuntu Server 24.04 LTS** (22.04 works the same), 64-bit,
  at least 7 GB RAM and 20 GB free disk.
- A wired connection to the router the phones and laptops use.
- A second computer for SSH, for the first sign-in, and to hold backup copies.
- A password manager, or a sealed paper note, for three secrets (§4).

---

## 1. Prepare the server

Do this at the server's own keyboard once. After that, everything can be done
over SSH.

**1.1 Update Ubuntu**

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y git curl openssl openssh-server unattended-upgrades
```

`unattended-upgrades` installs security updates by itself.

**1.2 Timezone and clock**

```bash
sudo timedatectl set-timezone Asia/Manila
timedatectl status
```

You should see `Time zone: Asia/Manila` and `System clock synchronized: yes`.
**The server's clock matters.** Attendance from the phones is checked against
it (`HRIS_CLOCK_SKEW_TOLERANCE`, 120 s), so a server that is minutes off will
flag honest records. If it says `synchronized: no`, run
`sudo timedatectl set-ntp true` and check again after a minute.

**1.3 A fixed LAN address**

The phones and laptops need an address that does not change. The simplest way
is a **DHCP reservation on the router**. In the router's admin page, find the
server under the connected devices and reserve its current address. Find that
address with:

```bash
ip -4 addr show | grep inet
```

The line that is not `127.0.0.1` gives it, for example `192.168.1.50`. This
guide writes `192.168.1.50` from here on. **Use your own everywhere you see it.**

**1.4 Do not let it sleep**

An old desktop or laptop may suspend itself, and the HRIS goes with it:

```bash
sudo systemctl mask sleep.target suspend.target hibernate.target hybrid-sleep.target
```

If the server is a **laptop**, also keep it running with the lid closed:

```bash
sudo sed -i 's/^#\?HandleLidSwitch=.*/HandleLidSwitch=ignore/' /etc/systemd/logind.conf
sudo sed -i 's/^#\?HandleLidSwitchExternalPower=.*/HandleLidSwitchExternalPower=ignore/' /etc/systemd/logind.conf
sudo systemctl restart systemd-logind
```

Optional: in the BIOS, set *Restore on AC power loss* to **Power On**, so the
server starts again after a brownout. Everything in the HRIS starts again with
it (§10).

**1.5 SSH from your own computer**

From the second computer:

```bash
ssh rusel@192.168.1.50
```

Use your Ubuntu user name in place of `rusel`. From here on, every command runs
in this SSH session on the server.

**1.6 Firewall**

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw enable
sudo ufw status
```

Be aware that Docker writes its own firewall rules for published ports, and
those bypass `ufw`. That is harmless here because only nginx publishes a port
(80). MySQL, PHP and Redis publish none, so they cannot be reached from outside
the machine whatever `ufw` says.

---

## 2. Install Docker

Use Docker's own packages, not Ubuntu's `docker.io` or the snap. Those can lack
the `docker compose` plugin this guide depends on.

```bash
sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Let your user run Docker without `sudo`. The cron backup in §9 needs this too.

```bash
sudo usermod -aG docker $USER
exit
```

SSH back in, then check:

```bash
docker run --rm hello-world
docker compose version
systemctl is-enabled docker
```

You should see *Hello from Docker!*, a Compose version of 2.x, and `enabled`.
`enabled` means Docker, and with it the HRIS, starts at every boot.

---

## 3. Get the code

The repository is private, so the server needs its own **read-only deploy key**.
It can pull but never push.

```bash
ssh-keygen -t ed25519 -C "hris-server" -f ~/.ssh/hris_deploy -N ""
cat ~/.ssh/hris_deploy.pub
```

On GitHub, open **ruselportes/HRIS → Settings → Deploy keys → Add deploy key**.
Name it `hris-server`, paste the line that was printed, and **leave
*Allow write access* unticked**. Then, on the server:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
    IdentityFile ~/.ssh/hris_deploy
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

cd ~
git clone git@github.com:ruselportes/HRIS.git capstone_hris
cd ~/capstone_hris
git log --oneline -3
```

The last command lists the three most recent commits. They should match GitHub.
The code is now in `~/capstone_hris`, which is also the path
`deploy/backup.sh`'s cron line expects.

---

## 4. Configure it: `.env.prod`

```bash
cd ~/capstone_hris
cp .env.prod.example .env.prod
chmod 600 .env.prod
```

`.env.prod` is gitignored and must never be committed. `chmod 600` makes it
readable by you alone.

**4.1 Generate the three secrets**

```bash
echo "DB_PASSWORD=$(openssl rand -hex 24)"
echo "DB_ROOT_PASSWORD=$(openssl rand -hex 24)"
echo "APP_KEY=base64:$(openssl rand -base64 32)"
```

Open the file (`nano .env.prod`) and paste each printed line over the empty one
with the same name. Then set the address people will use:

```
APP_URL=http://192.168.1.50
```

Save with Ctrl+O, Enter, then Ctrl+X.

**4.2 Save the secrets somewhere else, now**

Copy `DB_PASSWORD`, `DB_ROOT_PASSWORD` and `APP_KEY` into the team's password
manager, or onto paper kept somewhere safe. They matter for these reasons:

- **`APP_KEY` cannot be replaced.** Each phone's HMAC key in `device_keys` is
  encrypted with it. A new `APP_KEY` makes every bound phone's key unreadable,
  so every phone has to be bound again. A database backup is also useless for
  attendance verification without the `APP_KEY` it was made under.
- **`DB_PASSWORD` is fixed on the first start.** MySQL creates its user with
  whatever password `.env.prod` holds the first time `hris-mysql-data` is
  created. Changing the line later does not change MySQL, and the API can no
  longer connect (§12).

**4.3 The other settings**

Every other line has a working default and can stay as it is. Check two of them:

- `HRIS_REGIONAL_MINIMUM_WAGE=501.00` is flagged **[VERIFY]**. Confirm it
  against the Region VII wage order in force before payroll runs
  (`docs/PH_LABOR_AND_PAYROLL_EXPLAINED.md`).
- `CACHE_STORE=failover` uses the Redis cluster with MySQL behind it. Leave it
  unless §11 tells you to change it.

Check that nothing required is still blank:

```bash
grep -E '^(DB_PASSWORD|DB_ROOT_PASSWORD|APP_KEY|APP_URL)=' .env.prod | grep -E '=$' || echo "all four set"
```

This should print `all four set`.

**4.4 The `hris` shortcut**

Every command from here on is `docker compose` with the production file and its
settings. Save that as the shortcut `hris`, usable from any folder:

```bash
echo "alias hris='docker compose --env-file \$HOME/capstone_hris/.env.prod -f \$HOME/capstone_hris/compose.prod.yaml'" >> ~/.bashrc
source ~/.bashrc
hris config --services
```

The last command lists the services: `mysql`, `api`, `scheduler`, `web`,
`redis-1` … `redis-6` and `redis-cluster-init`. If it prints
`set APP_KEY in .env.prod` or a similar message, that line in `.env.prod` is
still empty.

---

## 5. First start

```bash
cd ~/capstone_hris
hris up -d --build
```

The first build takes **10–20 minutes** on an old machine. It downloads the
images, compiles the PHP Redis extension, installs the PHP packages and builds
the React portal. Later updates are faster because the downloads are cached.

When the command returns, check:

```bash
hris ps -a
```

After a minute or two you should see:

| Service | Status |
|---|---|
| `mysql`, `api`, `web` | `Up … (healthy)` |
| `scheduler` | `Up` |
| `redis-1` … `redis-6` | `Up … (healthy)` |
| `redis-cluster-init` | `Exited (0)`. That is correct: it formed the cluster and finished. |

If something shows `(health: starting)`, wait a minute and run `hris ps -a`
again. If something shows `unhealthy`, `Restarting` or an exit code other than
0, go to §12.

Confirm the database was built and the cluster formed:

```bash
hris logs api | grep -iE "migrat|error" | tail -5
hris logs redis-cluster-init | tail -3
```

The API log should show migrations running (the first start creates about 30
tables). The cluster log should end with `[OK] All 16384 slots covered.`

---

## 6. First-run data and the first administrator

A new database is empty. It has no roles, so nobody can be given one, and no
accounts, so nobody can sign in. Load the reference data, then make the first
administrator:

```bash
hris exec -u www-data api php artisan db:seed --class=RoleSeeder --force
hris exec -u www-data api php artisan db:seed --class=HolidaySeeder --force
hris exec -u www-data api php artisan hris:make-admin
```

`hris:make-admin` asks for a first name, a last name and a password twice. The
password is hidden as you type and must be at least 12 characters. It then
prints the new **employee code**:

```
Administrator created.
  Employee code: ADC-0001   (sign in with this)
  Name:          Rusel Portes
```

Add `--email you@example.com` to the command if the admin should also be able to
sign in with an email address. The account is recorded in the audit log as
`ADMIN_CREATED_FROM_CONSOLE`. The command refuses to run a second time once an
admin exists; further accounts are made in the app (§7). `--force` exists for
one case only: every admin password has been lost.

> ⚠️ **Never run a bare `db:seed`** (without `--class=`) on this server. It
> loads the demo data, including an **administrator whose password is literally
> `password`**, and demo workers whose birth dates are in the repository, so
> anyone could activate their portal accounts. `-u www-data` runs artisan as
> the same user as the web workers. Running it as root can leave files in
> storage that the portal then cannot write.

`HolidaySeeder` loads the **2026** holidays. Add each later year's holidays on
the Holidays page once they are proclaimed. The payroll engine reads them from
there.

---

## 7. Check that it works, then set it up

**7.1 From the server**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/up
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/api/sites
```

The first should print `200`, meaning the API is up. The second should print
`401`, meaning the API answers and refuses a request with no sign-in, as it
should.

**7.2 From another computer on the same network**

Open `http://192.168.1.50/` in a browser. The sign-in page should load. Sign in
with the employee code from §6 (`ADC-0001`) and your password.

**7.3 The cache is really Redis**

Signed in as the admin, open the Employees page once, then run:

```bash
hris exec redis-1 redis-cli cluster info | grep -E "cluster_state|cluster_known_nodes"
for n in 1 2 3 4 5 6; do echo "redis-$n: $(hris exec redis-$n redis-cli dbsize)"; done
hris exec api ls storage/framework/cache/cache-degraded
```

You should see:

- `cluster_state:ok` and `cluster_known_nodes:6`.
- At least one node with more than 0 keys. A primary and its replica show the
  same count.
- `No such file or directory` for the last command. The file exists only while
  the API has given up on Redis for a cooldown (runbook §3).

**7.4 Set up the people (in the portal, as the admin)**

1. **Sites**: create each project site.
2. **Employees**: add HR staff first, with the HR role and a password, so HR can
   take over the rest of the records. Then add engineers, foremen and executives
   with passwords. These roles sign in.
3. **Workers and operators** need no password. Enter their **date of birth**:
   a worker activates their own portal account with employee code and birth
   date (Add-on B). Without a birth date, they cannot activate.
4. **Crews**: assign foremen and workers to crews and sites.
5. Tell each person their employee code. Tell staff their first password in
   person; they change it after signing in.

---

## 8. Phones and access from outside

**8.1 The foreman app on the same network**

Install `app-release.apk` on the phone. On the sign-in screen, set **Server** to
the server's address:

```
http://192.168.1.50
```

The app adds `/api` by itself and remembers the address. Sign in with a
foreman's employee code and password. The phone binds its hardware key at the
first sign-in.

**8.2 From outside the office network: Tailscale (recommended for the team)**

Tailscale gives the server a private address that works from anywhere and does
not change. The traffic is encrypted between devices.

```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up
tailscale ip -4
```

`tailscale up` prints a link. Open it and sign in to approve the server. The
last command prints an address like `100.101.102.103`. Install Tailscale on each
phone and laptop that needs access and sign in to the same account. Then use
`http://100.101.102.103` in the browser and in the app's **Server** field.

If Tailscale becomes the main way in, set `APP_URL` to that address in
`.env.prod` and run `hris up -d`.

**8.3 A public link for a demo: Cloudflare quick tunnel**

```bash
hris --profile tunnel up -d
hris logs tunnel 2>&1 | grep -o "https://[a-z0-9-]*\.trycloudflare\.com" | tail -1
```

This prints a public `https://…trycloudflare.com` address, which anyone on the
internet can open. The address **changes every time the tunnel restarts**, so
the phones' **Server** field must be updated each time. Stop the tunnel when
the demo ends:

```bash
hris stop tunnel
```

> Transport security: the LAN is plain HTTP, so a phone's sign-in token crosses
> the local network unencrypted. Tailscale and the tunnel are encrypted. HTTPS
> on the LAN is still owed before anyone outside the team uses the system
> (`docs/TASKS.md`, *Operations*).

---

## 9. Backups

The server has one old disk. A backup on that same disk does not survive the
disk failing, so copy backups off the machine as well.

**9.1 Nightly database dump**

```bash
chmod +x ~/capstone_hris/deploy/backup.sh
~/capstone_hris/deploy/backup.sh
ls -lh ~/capstone_hris/backups/
```

The manual run should print `wrote backups/hris-….sql.gz`. Then schedule it for
2 AM every night:

```bash
crontab -e
```

Choose `nano` if asked, and add this line at the bottom:

```
0 2 * * * /home/rusel/capstone_hris/deploy/backup.sh >> /home/rusel/capstone_hris/backups/backup.log 2>&1
```

Use your own user name in both paths. The script keeps 14 days of dumps and
deletes older ones.

**9.2 Copy them off the server**

At least weekly, from your own computer:

```bash
scp "rusel@192.168.1.50:~/capstone_hris/backups/*.sql.gz" .
```

A USB drive plugged into the server works too. Keep a copy of `.env.prod` with
the backups, somewhere private. **Without its `APP_KEY`, a restored database
cannot verify any phone's records.**

**9.3 Restore drill: prove a backup works (still owed; do it once)**

This restores the newest dump into a **scratch** database beside the real one
and compares the two. The real data is not touched.

```bash
cd ~/capstone_hris
latest=$(ls -t backups/hris-*.sql.gz | head -1); echo "$latest"
hris exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE hris_restore_test"'
gunzip -c "$latest" | hris exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" hris_restore_test'
for db in hris hris_restore_test; do
  hris exec -T mysql sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -N -e \"SELECT '$db', (SELECT COUNT(*) FROM $db.employees), (SELECT COUNT(*) FROM $db.attendances), (SELECT COUNT(*) FROM $db.crypto_signatures), (SELECT COUNT(*) FROM $db.payrolls), (SELECT COALESCE(SUM(net_pay),0) FROM $db.payrolls)\""
done
hris exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE hris_restore_test"'
```

The two rows should match: employees, attendance records, signatures, payroll
rows, and total net pay. Anything written after the dump was taken shows up only in
`hris`. Record the date and the figures in `docs/TASKS.md` under *Restore
drill*. The `Using a password on the command line interface can be insecure`
lines are MySQL's standard warning. They are harmless here, because the
password never leaves the container.

**9.4 A real restore (after data loss)**

Only when the live database has to be replaced. Everything written since the
dump is lost.

```bash
cd ~/capstone_hris
./deploy/backup.sh                       # keep what is there now, just in case
hris stop api scheduler web
gunzip -c backups/hris-YYYYMMDD-HHMMSS.sql.gz | hris exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" hris'
hris restart redis-1 redis-2 redis-3 redis-4 redis-5 redis-6
hris start api scheduler web
```

Restarting the Redis nodes empties them, so no copy of the data from before the
restore can be served. The cluster re-forms by itself in a few seconds. The
`.env.prod` in use must hold the **same `APP_KEY`** as when the dump was made.

---

## 10. Updating, rolling back, everyday commands

**10.1 Deploying a new version**

Commit and push from the development machine as usual. Then, on the server:

```bash
cd ~/capstone_hris
./deploy/backup.sh
git pull
hris up -d --build
hris ps -a
```

Migrations run by themselves when the new API starts. Take the backup first
every time: it is the way back if a migration goes wrong. Only the containers
whose image or settings changed are recreated. The Redis cluster keeps running,
and `redis-cluster-init` reports `Redis cluster already formed.`

After a few updates, reclaim the disk that old images use:

```bash
docker image prune -f
```

**10.2 Rolling back**

```bash
cd ~/capstone_hris
git log --oneline -5                  # find the last good commit
git checkout <that-commit>
hris up -d --build
```

Code rolls back, but **database migrations do not**. If the bad version ran a
migration, restore the backup taken just before it (§9.4). Return to normal
updates with `git checkout main` and `git pull`.

**10.3 Everyday commands**

| To | Run |
|---|---|
| See what is running | `hris ps -a` |
| Follow the API's log | `hris logs -f --tail 100 api` |
| Restart one service | `hris restart api` |
| Apply a `.env.prod` change | `hris up -d` (recreates what changed; the API re-reads its settings at start) |
| Stop everything, keep the data | `hris down` |
| Start again | `hris up -d` |
| Disk used by Docker | `docker system df` |

> ⚠️ **Never `hris down -v`.** `-v` deletes the volumes, and one of them is the
> database.

**After a power cut or reboot** there is nothing to do. Docker starts at boot,
every service has `restart: unless-stopped`, and the Redis nodes re-form their
cluster from what they saved (their addresses are fixed for exactly this
reason). `redis-cluster-init` does not run again, and does not need to. Check
with `hris ps -a` and §7.3.

---

## 11. Operating the Redis cache

The full runbook, with drills, measured figures and defense wording, is
`docs/REDIS_CLUSTER_RUNBOOK.md`. Its commands say `docker compose`. On the
server, type `hris` in its place: `docker compose exec redis-1 …` becomes
`hris exec redis-1 …`.

**What happens when it fails.** A stopped node's replica takes over in about 9
seconds. With the whole cluster down, every page keeps working from MySQL:
slower, never wrong. You do not have to act for the system to keep working.

**A one-node failover drill on the server** (good before a defense). Stop
the primary that holds the employee list's version counter, so every
Employees read notices:

```bash
for n in 1 2 3; do echo "redis-$n: $(hris exec redis-$n redis-cli --scan --pattern '*employees:version*')"; done
hris stop redis-1                   # the one that printed a key; redis-1 here
```

Keep opening the Employees page. The first read takes a few seconds, and every
page keeps working. Then check:

```bash
hris exec api cat storage/framework/cache/cache-degraded     # the breaker, open for 15 s
hris exec redis-2 redis-cli cluster info | grep cluster_state
hris logs api scheduler --since 5m | grep -i "cache unavailable"
hris start redis-1                  # it rejoins as a replica of the node that replaced it
hris exec redis-1 redis-cli cluster failover                 # optional: make it primary again
```

Measured on the production stack (§14): the first read after the stop took
2.6 s and opened the breaker. Its replica was promoted, and 15 s later reads
were back on Redis. Every response was 200. Two things differ from the
runbook's development figures:

- **Only the failure is logged.** `LOG_LEVEL=warning` in production drops the
  `Cache answering again.` line, which is `info`. Recovery shows instead as the
  `cache-degraded` file going away. The file goes on the first cached read
  after the cooldown, so it can linger on an idle system.
- **The scheduler logs its own failures.** It uses the cache too, so a
  `Cache unavailable` line can come from `scheduler` as well as `api`. Both
  share the breaker through the same storage volume. When one finds Redis down,
  the other stops trying as well.

**The kill switch.** If the cache ever misbehaves, take Redis out of the path
entirely:

```bash
sed -i 's/^CACHE_STORE=.*/CACHE_STORE=database/' ~/capstone_hris/.env.prod
hris up -d
```

The API then caches in MySQL only. Undo it with `CACHE_STORE=failover` and
`hris up -d`. The Redis containers can keep running either way.

**Rebuilding the cluster from scratch** (runbook §8, e.g. after a subnet
change or `noaddr` in `cluster nodes`):

```bash
hris rm -sfv redis-1 redis-2 redis-3 redis-4 redis-5 redis-6
hris up -d
```

`-v` here removes only those six containers' own scratch volumes, which hold
their cluster memory. It does not touch the database. `redis-cluster-init` then
forms a fresh cluster.

---

## 12. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `hris up` stops with `set APP_KEY in .env.prod` (or `DB_PASSWORD`, `APP_URL`, `DB_ROOT_PASSWORD`) | That line in `.env.prod` is empty. Fill it in (§4). |
| `Bind for 0.0.0.0:80 failed: port is already allocated` | Something else holds port 80, often a pre-installed Apache. Find it with `sudo ss -ltnp \| grep ':80 '`. Remove it (`sudo systemctl disable --now apache2`), or add `WEB_PORT=8080` to `.env.prod` and use `http://192.168.1.50:8080`. |
| `Pool overlaps with other one on this address space` | Another Docker network already uses `172.28.201.0/24`. Change the subnet and the six addresses in `compose.prod.yaml` together (its `networks:` comment says where), then `hris down` and `hris up -d`. |
| `api` restarts, and its log says `Access denied for user 'hris'` | `DB_PASSWORD` changed after MySQL was first created (§4.2). Put the original back. To change it for real: `hris exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "ALTER USER \"hris\"@\"%\" IDENTIFIED BY \"NEW\""'`, then set the same `NEW` in `.env.prod` and `hris up -d`. |
| The browser shows **502 Bad Gateway** | The API is not up yet, or it crashed. Check `hris ps -a` and `hris logs --tail 100 api`. |
| The portal loads, but a page shows an error | `hris logs --tail 100 api`. In production, Laravel logs there (`LOG_CHANNEL=stderr`), not to a file. |
| *Too many attempts* at sign-in | The per-account lockout. It lifts by itself after 15 minutes. |
| A phone cannot reach the server | The phone must be on the same Wi-Fi (or on Tailscale). Check its **Server** field. From the phone's browser, `http://192.168.1.50/up` should load. |
| Phones' records are flagged for clock skew | The server's clock is off: `timedatectl status` (§1.2). |
| `cluster_state:fail`, or `noaddr` in `cluster nodes` | Rebuild the cluster (§11). The system keeps working from MySQL meanwhile. |
| `cache-degraded` exists and does not go away | The cache is failing repeatedly. Look at `hris logs api \| grep -i cache`, then check the cluster (§7.3). If needed, use the kill switch (§11). |
| The disk is filling | Run `docker system df` and `du -sh ~/capstone_hris/backups`, then `docker image prune -f`. Container logs are already capped at 30 MB per service. |
| `hris: command not found` | You are in a new shell before `~/.bashrc` was reloaded. Run `source ~/.bashrc`, or redo §4.4. |

---

## 13. Before real users: checklist

- [ ] `.env.prod` has strong generated values and is `chmod 600`. `APP_KEY` and
      both database passwords are saved outside the server.
- [ ] Only `RoleSeeder` and `HolidaySeeder` were run. There is no account
      whose password is `password`.
- [ ] The admin password is at least 12 characters and known to two people.
- [ ] `HRIS_REGIONAL_MINIMUM_WAGE` is checked against the wage order in force.
- [ ] Nightly backups run (`backups/backup.log`), a copy lives off the server,
      and the restore drill (§9.3) passed once.
- [ ] The server clock is synchronised (§1.2).
- [ ] The tunnel is off except during demos.
- [ ] Still owed, tracked in `docs/TASKS.md`: HTTPS on the LAN, a decided
      stable address for the phones, and team sign-off on the Redis stack
      amendment (CLAUDE.md §2).

---

## 14. How this guide was verified

On 2026-09-22 the production stack from this commit was built and run on the
development machine. It used port 8085, because Windows reserves 80, and a
throwaway `.env.prod`. Starting from an empty database:

- **§5.** Every service came up as in the table. `redis-cluster-init` exited 0
  with `[OK] All 16384 slots covered.`, and the API ran the migrations.
  Inside the API, the cache store was `failover` with phpredis and all six
  seeds.
- **§6.**
  - `hris:make-admin` before the roles existed refused and named the seeder.
  - Run with `exec -T` and no terminal, it refused with *No password was
    given* and created nothing.
  - It then created `ADC-0001`.
  - A second run refused, because an admin existed.
- **§7.1–§7.3.**
  - `/up` returned 200, `/api/sites` with no sign-in returned 401, and the
    portal returned 200.
  - The new admin signed in with the employee code.
  - The Employees and Sites reads landed in Redis, spread over the three
    primaries and their replicas. The MySQL `cache` table stayed at 0 rows.
  - Each node reported `maxmemory 64mb` and `volatile-lru`.
- **§11, the failover drill.**
  - Stopping a primary that held no key being read cost nothing: every read
    returned 200, and its replica took over within about 12 s.
  - Stopping the one that held `employees:version`:
    - the first read took 2.6 s and opened the breaker for 15 s;
    - reads were then served from MySQL at about 0.05 s;
    - reads went back to Redis once the cooldown ended;
    - every response was 200.
  - The returning node rejoined as a replica. `cluster failover` restored the
    original layout.
- **§9.3 and §9.4.**
  - The restore-drill commands ran as written, and the two rows matched.
  - Restarting all six nodes at once re-formed the cluster by itself in about
    10 s, with no `noaddr`, and every read stayed 200.
- **§11, rebuild.** `rm -sfv` of the six nodes, then `up -d`, formed a fresh
  cluster and left the database volume alone.
- **§10.1.** Running `up -d` again kept the cluster (the same node ids), and
  init printed `Redis cluster already formed.`

The server-specific steps could not be exercised without the server itself:
§1–§3, the Ubuntu packages, cron, `ufw`, Tailscale, and a real reboot. Treat
the first real run as their test, and fix this guide wherever the server
disagrees with it.
