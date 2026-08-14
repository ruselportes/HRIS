"""
Textbook-standard UML Use Case Diagram Generator
- Stick-figure actors (circle head + body + arms + legs)
- Horizontal oval use cases inside a rectangular system boundary
- Clean white background, black outlines
- Proper spacing so NOTHING overlaps
"""
import os, sys, textwrap

sys.stdout.reconfigure(encoding='utf-8')

def xe(text):
    """Escape special XML/SVG characters so SVG files parse correctly."""
    return (text
        .replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
    )
DIAGRAMS_DIR = r"c:\capstone\diagrams"
os.makedirs(DIAGRAMS_DIR, exist_ok=True)

# ──────────────────────────────────────────────────────
# SVG primitives
# ──────────────────────────────────────────────────────
ACTOR_H = 130   # total vertical space a stick figure + label occupies
UC_H    = 70    # vertical space for each oval row
HEAD_R  = 16
BODY_H  = 38
ARM_W   = 24
LEG_H   = 30

def stick_figure_svg(cx, cy, label):
    """Draw one UML stick-figure actor centered at (cx, cy)."""
    head_top = cy - BODY_H // 2 - HEAD_R
    head_cy  = head_top + HEAD_R
    body_y1  = head_cy + HEAD_R
    body_y2  = body_y1 + BODY_H
    arm_y    = body_y1 + BODY_H // 3
    leg_by   = body_y2 + LEG_H
    lbl_y    = leg_by + 18

    # Wrap label to two lines max
    words = label.split()
    lines, cur = [], ""
    for w in words:
        test = (cur + " " + w).strip()
        lines = lines if len(test) <= 13 else (lines + [cur])
        cur = test if len(test) <= 13 else w
    lines.append(cur)
    lines = [l for l in lines if l]

    lbl_svg = "".join(
        f'<text x="{cx}" y="{lbl_y + i*15}" text-anchor="middle" '
        f'font-family="Arial,sans-serif" font-size="12" font-weight="bold" fill="#0f172a">{xe(ln)}</text>\n'
        for i, ln in enumerate(lines)
    )

    return (
        f'<circle cx="{cx}" cy="{head_cy}" r="{HEAD_R}" stroke="#0f172a" stroke-width="2" fill="white"/>\n'
        f'<line x1="{cx}" y1="{body_y1}" x2="{cx}" y2="{body_y2}" stroke="#0f172a" stroke-width="2"/>\n'
        f'<line x1="{cx-ARM_W}" y1="{arm_y}" x2="{cx+ARM_W}" y2="{arm_y}" stroke="#0f172a" stroke-width="2"/>\n'
        f'<line x1="{cx}" y1="{body_y2}" x2="{cx-ARM_W}" y2="{leg_by}" stroke="#0f172a" stroke-width="2"/>\n'
        f'<line x1="{cx}" y1="{body_y2}" x2="{cx+ARM_W}" y2="{leg_by}" stroke="#0f172a" stroke-width="2"/>\n'
        + lbl_svg
    )

def actor_torso_cy(cy):
    """Return the body midpoint Y for association line attachment."""
    head_top = cy - BODY_H // 2 - HEAD_R
    head_cy  = head_top + HEAD_R
    body_y1  = head_cy + HEAD_R
    return (body_y1 + body_y1 + BODY_H) // 2   # mid-torso

def oval_svg(cx, cy, rx, ry, label):
    """Draw one UML use-case oval with wrapped text."""
    lines = textwrap.wrap(label, width=24)
    n = len(lines)
    lh = 15  # line height
    text_svg = "".join(
        f'<text x="{cx}" y="{cy + (i-(n-1)/2)*lh + 5}" text-anchor="middle" '
        f'font-family="Arial,sans-serif" font-size="12" font-weight="bold" fill="#0f172a">{xe(ln)}</text>\n'
        for i, ln in enumerate(lines)
    )
    return (
        f'<ellipse cx="{cx}" cy="{cy}" rx="{rx}" ry="{ry}" '
        f'stroke="#0f172a" stroke-width="2" fill="white"/>\n'
        + text_svg
    )

def line_svg(x1, y1, x2, y2):
    return f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#475569" stroke-width="1.5"/>\n'


# ──────────────────────────────────────────────────────
# 1. MASTER SYSTEM USE CASE MAP
# ──────────────────────────────────────────────────────
#   Left actors: Foreman, Engineer
#   Right actors: HR Officer, System Admin
#   Use cases: 15, evenly spaced vertically

MASTER_UCS = [
    ("UC-01: Authenticate User & RBAC",          ["Foreman","Engineer","HR Officer","Admin"]),
    ("UC-02: Manage Worker Profiles & Skills",   ["HR Officer"]),
    ("UC-03: Deploy Crew to Project Site",       ["Engineer"]),
    ("UC-04: Log Attendance Checklist Offline",  ["Foreman"]),
    ("UC-05: Encrypt Local Database Storage",    ["Foreman"]),
    ("UC-06: Capture Monotonic Hardware Time",   ["Foreman"]),
    ("UC-07: Chain Logs via HMAC Ledger",        ["Foreman"]),
    ("UC-08: Sign Payload with Hardware Key",    ["Foreman"]),
    ("UC-09: Auto-Sync Attendance Records",      ["Foreman"]),
    ("UC-10: Override Late Foreman Shift",       ["Foreman","Engineer"]),
    ("UC-11: Reassign Crew to Stand-In",         ["HR Officer"]),
    ("UC-12: Sign Off Retroactive Recovery",     ["Engineer"]),
    ("UC-13: Compute Bi-Weekly Payroll",         ["HR Officer"]),
    ("UC-14: Process OT & Leave Requests",       ["Engineer","HR Officer"]),
    ("UC-15: View Executive Labor Analytics",    ["Admin","HR Officer"]),
]

MASTER_ACTORS = {
    "Foreman":   {"side": "left",  "label": "Site Foreman"},
    "Engineer":  {"side": "left",  "label": "Site Engineer"},
    "HR Officer":{"side": "right", "label": "HR / Payroll\nOfficer"},
    "Admin":     {"side": "right", "label": "System Admin"},
}

def gen_master():
    n_uc     = len(MASTER_UCS)
    UC_GAP   = 80          # px between oval centres
    TOP_PAD  = 125         # from svg top to first oval centre
    BOT_PAD  = 60
    BOX_L    = 220         # left edge of system boundary
    BOX_R    = 980         # right edge of system boundary
    BOX_W    = BOX_R - BOX_L
    BOX_T    = 55
    UC_CX    = (BOX_L + BOX_R) // 2   # 600
    UC_RX    = 240
    UC_RY    = 28

    uc_ys = [TOP_PAD + i * UC_GAP for i in range(n_uc)]
    total_uc_h = uc_ys[-1] - uc_ys[0]

    # Actor x positions
    left_cx  = 100
    right_cx = 1100
    W = 1200

    # 2 left actors, 2 right actors — spread across uc range
    actor_ys = {
        "Foreman":    uc_ys[0]  + total_uc_h * 0 // 3,
        "Engineer":   uc_ys[0]  + total_uc_h * 2 // 3,
        "HR Officer": uc_ys[0]  + total_uc_h * 1 // 4,
        "Admin":      uc_ys[0]  + total_uc_h * 3 // 4,
    }

    H = TOP_PAD + n_uc * UC_GAP + BOT_PAD + 20
    BOX_H = H - BOX_T - 20

    svg  = f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" style="background:#f9fafb;">\n'
    svg += f'  <rect x="{BOX_L}" y="{BOX_T}" width="{BOX_W}" height="{BOX_H}" rx="6" fill="white" stroke="#0f172a" stroke-width="2.5"/>\n'
    svg += f'  <text x="{UC_CX}" y="{BOX_T+30}" text-anchor="middle" font-family="Arial,sans-serif" font-size="17" font-weight="bold" fill="#0f172a">HRIS System &#8212; Arcenas Development Corp</text>\n'

    # Association lines
    for uc_label, actors in MASTER_UCS:
        uc_idx = [u[0] for u in MASTER_UCS].index(uc_label)
        uc_cy  = uc_ys[uc_idx]
        for a in actors:
            info = MASTER_ACTORS[a]
            ay   = actor_ys[a]
            acy  = actor_torso_cy(ay)
            if info["side"] == "left":
                svg += line_svg(left_cx + ARM_W, acy, UC_CX - UC_RX, uc_cy)
            else:
                svg += line_svg(right_cx - ARM_W, acy, UC_CX + UC_RX, uc_cy)

    # Use case ovals
    for uc_label, _ in MASTER_UCS:
        uc_idx = [u[0] for u in MASTER_UCS].index(uc_label)
        uc_cy  = uc_ys[uc_idx]
        svg += oval_svg(UC_CX, uc_cy, UC_RX, UC_RY, uc_label)

    # Actors
    for a_key, info in MASTER_ACTORS.items():
        ay  = actor_ys[a_key]
        cx  = left_cx if info["side"] == "left" else right_cx
        svg += stick_figure_svg(cx, ay, info["label"])

    svg += '</svg>\n'
    return svg


# ──────────────────────────────────────────────────────
# 2. INDIVIDUAL UC DIAGRAMS (UC-01 to UC-15)
# ──────────────────────────────────────────────────────
IND_UCS = [
    {
        "id": "use_case_01", "title": "UC-01: User Authentication & RBAC",
        "actors": ["System Admin", "HR Officer", "Site Engineer", "Site Foreman"],
        "usecases": ["Login to System", "Manage User Roles & Permissions", "Reset User Passwords"],
        # actor index → list of UC indices it connects to
        "connections": {0: [0,1,2], 1: [0,1], 2: [0,1], 3: [0]},
    },
    {
        "id": "use_case_02", "title": "UC-02: Worker Registry & Skill Management",
        "actors": ["HR Officer"],
        "usecases": ["Register New Worker", "Edit Worker Profile", "Assign Trade Skill", "Set Daily Rate"],
        "connections": {0: [0,1,2,3]},
    },
    {
        "id": "use_case_03", "title": "UC-03: Crew Assignment & Site Deployment",
        "actors": ["Site Engineer"],
        "usecases": ["Create Project Site", "Assign Workers to Crew", "Assign Foreman to Crew"],
        "connections": {0: [0,1,2]},
    },
    {
        "id": "use_case_04", "title": "UC-04: Offline Digital Attendance Checklist",
        "actors": ["Site Foreman"],
        "usecases": ["Open Crew Checklist", "Mark Worker Present / Absent", "Submit Attendance Batch Offline"],
        "connections": {0: [0,1,2]},
    },
    {
        "id": "use_case_05", "title": "UC-05: Local Storage Hardware Encryption",
        "actors": ["Site Foreman"],
        "usecases": ["Initialize SQLCipher DB", "Retrieve TEE Encryption Key", "Encrypt Records (AES-256)"],
        "connections": {0: [0,1,2]},
    },
    {
        "id": "use_case_06", "title": "UC-06: Monotonic Hardware Clock Capture",
        "actors": ["Site Foreman"],
        "usecases": ["Fetch Hardware Monotonic Counter", "Store Online Time Anchor", "Compute True Event Timestamp"],
        "connections": {0: [0,1,2]},
    },
    {
        "id": "use_case_07", "title": "UC-07: HMAC Hash Chain Ledger",
        "actors": ["Site Foreman"],
        "usecases": ["Retrieve Previous Record Hash", "Compute HMAC-SHA256 Chain", "Append Immutable Log Entry"],
        "connections": {0: [0,1,2]},
    },
    {
        "id": "use_case_08", "title": "UC-08: Hardware TEE Payload Signing",
        "actors": ["Site Foreman"],
        "usecases": ["Build Sync Payload", "Sign via Android Keystore / iOS TEE", "Attach ECDSA Signature"],
        "connections": {0: [0,1,2]},
    },
    {
        "id": "use_case_09", "title": "UC-09: Auto Background Sync Engine",
        "actors": ["Site Foreman"],
        "usecases": ["Detect Network Online", "Transmit Queued Payloads to API", "Receive Server Acknowledgment"],
        "connections": {0: [0,1,2]},
    },
    {
        "id": "use_case_10", "title": "UC-10: Late Foreman Shift Override",
        "actors": ["Site Foreman", "Site Engineer"],
        "usecases": ["Apply Default Shift Start (7 AM)", "Select Delay Justification Reason", "Flag Record for Audit Review"],
        "connections": {0: [0,1], 1: [2]},
    },
    {
        "id": "use_case_11", "title": "UC-11: 1-Click Crew Re-Assignment",
        "actors": ["HR Officer"],
        "usecases": ["Flag Foreman Absent", "Select Stand-In Foreman / Lead Man", "Reassign Crew Instantly"],
        "connections": {0: [0,1,2]},
    },
    {
        "id": "use_case_12", "title": "UC-12: Retroactive Crew Recovery Sign-Off",
        "actors": ["Site Engineer"],
        "usecases": ["Generate Recovery Attendance Batch", "Review Site Work Logs", "Apply Digital Sign-Off"],
        "connections": {0: [0,1,2]},
    },
    {
        "id": "use_case_13", "title": "UC-13: Automated Payroll Computation",
        "actors": ["HR Officer"],
        "usecases": ["Compute Regular Daily Pay", "Apply OT Premium (1.25x)", "Apply Holiday Premium (2.0x)", "Generate & Export Payslip"],
        "connections": {0: [0,1,2,3]},
    },
    {
        "id": "use_case_14", "title": "UC-14: Overtime & Leave Filing Workflow",
        "actors": ["Site Engineer", "HR Officer"],
        "usecases": ["Submit OT / Leave Request", "Approve or Reject Request", "Apply Approved Hours to Payroll"],
        "connections": {0: [0,1], 1: [1,2]},
    },
    {
        "id": "use_case_15", "title": "UC-15: Executive Labor Analytics Dashboard",
        "actors": ["HR Officer"],
        "usecases": ["View Labor Cost Dashboard", "View Site Punctuality Rates", "Review Audit Override Event Log"],
        "connections": {0: [0,1,2]},
    },
]

def gen_individual(defn):
    actors   = defn["actors"]
    usecases = defn["usecases"]
    title    = defn["title"]
    conns    = defn["connections"]  # {actor_idx: [uc_idx, ...]}

    n_a = len(actors)
    n_u = len(usecases)

    # Layout constants
    ACTOR_COL_W = 170    # width for actor column (left side)
    UC_RX       = 200
    UC_RY       = 30
    TOP_PAD     = 90
    UC_GAP      = 90     # vertical gap between oval centres
    ACTOR_GAP   = 160    # vertical gap between actor centres

    # Canvas height: taller of the two columns + generous bottom padding
    uc_total_h    = (n_u - 1) * UC_GAP
    actor_total_h = (n_a - 1) * ACTOR_GAP
    content_h     = max(uc_total_h, actor_total_h)
    # Bottom pad must account for last actor's legs + label (≈ 100px below centre)
    BOT_PAD = 120
    H = TOP_PAD + content_h + BOT_PAD

    W = 820
    BOX_L = ACTOR_COL_W
    BOX_W = W - ACTOR_COL_W - 10
    BOX_T = 10
    UC_CX = BOX_L + BOX_W // 2

    # Y positions: ovals always start at TOP_PAD
    uc_ys = [TOP_PAD + i * UC_GAP for i in range(n_u)]

    # Actors: centre them on the oval midpoint, then clamp so top actor head never clips
    uc_mid   = (uc_ys[0] + uc_ys[-1]) // 2
    a_offset = (n_a - 1) * ACTOR_GAP // 2
    actor_ys_raw = [uc_mid - a_offset + i * ACTOR_GAP for i in range(n_a)]
    # head_top of first actor ≈ actor_cy - BODY_H//2 - HEAD_R = cy - 35; need ≥ 40
    MIN_ACTOR_TOP = 55   # minimum Y for first actor centre
    shift = max(0, MIN_ACTOR_TOP - actor_ys_raw[0])
    actor_ys = [y + shift for y in actor_ys_raw]
    # Expand canvas if actors now extend below current H
    last_actor_bottom = actor_ys[-1] + BODY_H // 2 + LEG_H + 40  # legs + label
    H = max(H, last_actor_bottom + 20)

    actor_cx = ACTOR_COL_W // 2

    svg  = f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" style="background:white;">\n'
    # System box height: based on oval span, not canvas height
    box_bottom = uc_ys[-1] + UC_RY + 50
    svg += f'  <rect x="{BOX_L}" y="{BOX_T}" width="{BOX_W}" height="{box_bottom - BOX_T}" rx="5" fill="white" stroke="#0f172a" stroke-width="2"/>\n'
    svg += f'  <text x="{UC_CX}" y="{BOX_T + 28}" text-anchor="middle" font-family="Arial,sans-serif" font-size="14" font-weight="bold" fill="#0f172a">HRIS System</text>\n'
    svg += f'  <text x="{UC_CX}" y="{BOX_T + 46}" text-anchor="middle" font-family="Arial,sans-serif" font-size="11" fill="#64748b">{xe(title)}</text>\n'

    # Association lines — draw to the oval left edge, not just the box wall
    for ai, uc_idxs in conns.items():
        ay  = actor_ys[ai]
        acy = actor_torso_cy(ay)
        for ui in uc_idxs:
            uc_cy = uc_ys[ui]
            svg  += line_svg(actor_cx + ARM_W, acy, UC_CX - UC_RX, uc_cy)

    # Ovals
    for i, uc_label in enumerate(usecases):
        svg += oval_svg(UC_CX, uc_ys[i], UC_RX, UC_RY, uc_label)

    # Actors
    for i, actor_label in enumerate(actors):
        svg += stick_figure_svg(actor_cx, actor_ys[i], actor_label)

    svg += '</svg>\n'
    return svg


# ──────────────────────────────────────────────────────
# WRITE ALL FILES
# ──────────────────────────────────────────────────────
# Master
with open(os.path.join(DIAGRAMS_DIR, "use_case_diagram.svg"), "w", encoding="utf-8") as f:
    f.write(gen_master())
print("SUCCESS: use_case_diagram.svg")

# Individuals
for defn in IND_UCS:
    with open(os.path.join(DIAGRAMS_DIR, f"{defn['id']}.svg"), "w", encoding="utf-8") as f:
        f.write(gen_individual(defn))
    print(f"SUCCESS: {defn['id']}.svg")

print("\nALL SVGs GENERATED.")
