import os
import subprocess
import sys

sys.stdout.reconfigure(encoding='utf-8')
diagrams_dir = r"c:\capstone\diagrams"
os.makedirs(diagrams_dir, exist_ok=True)

# Master UML Use Case Diagram with CSS Styling for maximum readability
master_use_case_mmd = """flowchart LR
    classDef actorStyle fill:#1e293b,stroke:#00f2fe,stroke-width:3px,color:#ffffff,font-size:16px,font-weight:bold;
    classDef ucStyle fill:#0f172a,stroke:#4facfe,stroke-width:2px,color:#f8fafc,font-size:15px,font-weight:600;
    classDef sysStyle fill:#020617,stroke:#38bdf8,stroke-width:2px,color:#38bdf8,font-size:18px,font-weight:bold;

    subgraph HRIS ["🏛️ HRIS SYSTEM BOUNDARY (Arcenas Development Corp)"]
        direction TB
        UC01(["UC-01: User Authentication & Role Access (RBAC)"]):::ucStyle
        UC02(["UC-02: Worker Registry & Skill Certifications"]):::ucStyle
        UC03(["UC-03: Crew Assignment & Site Deployment"]):::ucStyle
        UC04(["UC-04: Offline Digital Checklist Clock-In"]):::ucStyle
        UC05(["UC-05: Local Storage Hardware Encryption"]):::ucStyle
        UC06(["UC-06: Monotonic Hardware Clock Capture"]):::ucStyle
        UC07(["UC-07: Append-Only HMAC Hash Ledger"]):::ucStyle
        UC08(["UC-08: Hardware TEE Payload Signing"]):::ucStyle
        UC09(["UC-09: Automatic Background Sync Engine"]):::ucStyle
        UC10(["UC-10: Late Foreman Override & Shift Credit"]):::ucStyle
        UC11(["UC-11: 1-Click Absent Crew Re-Assignment"]):::ucStyle
        UC12(["UC-12: Retroactive Crew Recovery Sign-Off"]):::ucStyle
        UC13(["UC-13: Philippine Labor Code Payroll Computation"]):::ucStyle
        UC14(["UC-14: Overtime & Leave Approval Workflow"]):::ucStyle
        UC15(["UC-15: Executive Compliance & Labor Cost Analytics"]):::ucStyle
    end

    subgraph ACTORS ["👥 SYSTEM ACTORS"]
        direction TB
        Foreman["👷 Site Foreman"]:::actorStyle
        Engineer["👷‍♂️ Site Engineer"]:::actorStyle
        HR["💼 HR / Payroll Officer"]:::actorStyle
        Admin["⚙️ System Admin"]:::actorStyle
    end

    Admin ---> UC01

    Foreman ---> UC04
    Foreman ---> UC05
    Foreman ---> UC06
    Foreman ---> UC07
    Foreman ---> UC08
    Foreman ---> UC09
    Foreman ---> UC10

    Engineer ---> UC03
    Engineer ---> UC10
    Engineer ---> UC12
    Engineer ---> UC14

    HR ---> UC02
    HR ---> UC11
    HR ---> UC13
    HR ---> UC14
    HR ---> UC15
"""

# Render Master Diagram in High-Res 3000px PNG and SVG
master_mmd_path = os.path.join(diagrams_dir, "use_case_diagram.mmd")
master_png_path = os.path.join(diagrams_dir, "use_case_diagram.png")
master_svg_path = os.path.join(diagrams_dir, "use_case_diagram.svg")

with open(master_mmd_path, "w", encoding="utf-8") as f:
    f.write(master_use_case_mmd)

# Render PNG with 3000px width and scale factor 3
cmd_png = f'npx -y @mermaid-js/mermaid-cli -i "{master_mmd_path}" -o "{master_png_path}" -b "#090d16" -w 3000 -s 3'
cmd_svg = f'npx -y @mermaid-js/mermaid-cli -i "{master_mmd_path}" -o "{master_svg_path}" -b "#090d16"'

print("Rendering 3000px High-Res Master Use Case PNG...")
subprocess.run(cmd_png, shell=True)
print("Rendering Vector SVG Master Use Case...")
subprocess.run(cmd_svg, shell=True)

# Individual Use Cases with large fonts
individual_ucs = {
    "use_case_01": ("⚙️ System Admin", "UC-01: User Authentication & Role Access Control (RBAC)", "Authenticates user credentials using bcrypt and grants permissions based on RBAC role (Admin, HR, Engineer, Foreman)."),
    "use_case_02": ("💼 HR Officer", "UC-02: Worker Registry & Skill Certifications", "Registers worker profiles, skill categories (Mason, Steelman), and daily pay rates into central database."),
    "use_case_03": ("👷‍♂️ Site Engineer", "UC-03: Crew Assignment & Site Deployment", "Groups workers into site crews and deploys them to specific project sites and foremen."),
    "use_case_04": ("👷 Site Foreman", "UC-04: Offline Digital Checklist Clock-In", "Logs daily attendance for crew workers on remote job sites via tap checklist without internet connection."),
    "use_case_05": ("🤖 Mobile System", "UC-05: Local Storage Hardware Encryption", "Encrypts offline SQLite attendance database at rest using SQLCipher AES-256 with TEE hardware keys."),
    "use_case_06": ("🤖 Hardware Clock", "UC-06: Monotonic Hardware Clock Capture", "Reads elapsedRealtime() boot clock counter to compute true event time and bypass altered OS settings."),
    "use_case_07": ("🔒 Crypto Ledger", "UC-07: Append-Only HMAC Hash Chaining Ledger", "Chains every attendance entry with previous hash using HMAC-SHA256 to ensure local ledger immutability."),
    "use_case_08": ("🛡️ Hardware TEE", "UC-08: Hardware Security Module Payload Signing", "Signs batch attendance payload inside Android Keystore TEE / iOS Enclave using asymmetric ECDSA P-256 keys."),
    "use_case_09": ("📡 Network Worker", "UC-09: Automatic Background Network Sync Engine", "Detects cellular/Wi-Fi reconnection and automatically transmits queued encrypted payloads to Laravel REST API."),
    "use_case_10": ("👷 Site Foreman", "UC-10: Late Foreman Override & Shift-Start Credit", "Credits present workers for 7:00 AM start when foreman arrives late, while recording FOREMAN_LATE audit flag."),
    "use_case_11": ("💼 HR Officer", "UC-11: 1-Click Absent Crew Re-Assignment", "Re-assigns an absent foreman's crew to a stand-in foreman or lead man in real time via Web HRIS."),
    "use_case_12": ("👷‍♂️ Site Engineer", "UC-12: Retroactive Crew Recovery Sign-Off", "Reviews and digitally signs off on unrecorded historical crew attendance batches for payroll approval."),
    "use_case_13": ("💼 Payroll Officer", "UC-13: Philippine Labor Code Payroll Computation", "Computes gross pay, deductions, and rates (OT 1.25x, Night Diff 1.10x, Rest Day 1.30x, Regular Holiday 2.0x)."),
    "use_case_14": ("👷 Site Worker", "UC-14: Overtime & Leave Filing Approval Workflow", "Submits overtime and leave applications for multi-level approval by Site Engineer and HR."),
    "use_case_15": ("👔 Executive", "UC-15: Executive Compliance Audit & Labor Cost Analytics", "Displays executive dashboards for site labor costs, attendance punctuality rates, and audit event logs.")
}

for name, (actor, uc_name, desc) in individual_ucs.items():
    mmd_code = f"""flowchart LR
    classDef actorStyle fill:#1e293b,stroke:#00f2fe,stroke-width:3px,color:#ffffff,font-size:18px,font-weight:bold;
    classDef ucStyle fill:#0f172a,stroke:#38bdf8,stroke-width:3px,color:#f8fafc,font-size:18px,font-weight:bold;

    subgraph HRIS ["🏛️ HRIS SYSTEM BOUNDARY"]
        UC(["{uc_name}"]):::ucStyle
    end

    Actor["{actor}"]:::actorStyle ---> UC
"""
    mmd_path = os.path.join(diagrams_dir, f"{name}.mmd")
    png_path = os.path.join(diagrams_dir, f"{name}.png")
    svg_path = os.path.join(diagrams_dir, f"{name}.svg")
    
    with open(mmd_path, "w", encoding="utf-8") as f:
        f.write(mmd_code)
    
    cmd_p = f'npx -y @mermaid-js/mermaid-cli -i "{mmd_path}" -o "{png_path}" -b "#090d16" -w 1600 -s 2'
    cmd_s = f'npx -y @mermaid-js/mermaid-cli -i "{mmd_path}" -o "{svg_path}" -b "#090d16"'
    subprocess.run(cmd_p, shell=True)
    subprocess.run(cmd_s, shell=True)
    print(f"SUCCESS: Rendered High-Res PNG & SVG for {name}")

print("High-Resolution PNG and Vector SVG diagram generation complete!")
