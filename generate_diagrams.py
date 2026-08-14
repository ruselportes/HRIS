import os
import subprocess
import sys

sys.stdout.reconfigure(encoding='utf-8')
diagrams_dir = r"c:\capstone\diagrams"
os.makedirs(diagrams_dir, exist_ok=True)

# Updated complete UML Use Case Diagram covering all 15 Use Cases (UC-01 to UC-15)
use_case_mmd = """flowchart LR
    subgraph ACTORS ["👥 System Actors"]
        direction TB
        Foreman["👷 Site Foreman"]
        Engineer["👷‍♂️ Site Engineer"]
        HR["💼 HR / Payroll Officer"]
        Admin["⚙️ System Admin"]
    end

    subgraph HRIS ["🏛️ HRIS System Boundary (Arcenas Development Corp)"]
        direction TB
        UC01(["UC-01: User Authentication & Role Access (RBAC)"])
        UC02(["UC-02: Worker Registry & Skill Certifications"])
        UC03(["UC-03: Crew Assignment & Site Deployment"])
        UC04(["UC-04: Offline Digital Checklist Clock-In"])
        UC05(["UC-05: Local Storage Hardware Encryption"])
        UC06(["UC-06: Monotonic Hardware Clock Capture"])
        UC07(["UC-07: Append-Only HMAC Hash Ledger"])
        UC08(["UC-08: Hardware TEE Payload Signing"])
        UC09(["UC-09: Automatic Background Sync Engine"])
        UC10(["UC-10: Late Foreman Override & Shift Credit"])
        UC11(["UC-11: 1-Click Absent Crew Re-Assignment"])
        UC12(["UC-12: Retroactive Crew Recovery Sign-Off"])
        UC13(["UC-13: Philippine Labor Code Payroll Computation"])
        UC14(["UC-14: Overtime & Leave Approval Workflow"])
        UC15(["UC-15: Executive Compliance & Labor Cost Analytics"])
    end

    Admin --> UC01

    Foreman --> UC04
    Foreman --> UC05
    Foreman --> UC06
    Foreman --> UC07
    Foreman --> UC08
    Foreman --> UC09
    Foreman --> UC10

    Engineer --> UC03
    Engineer --> UC10
    Engineer --> UC12
    Engineer --> UC14

    HR --> UC02
    HR --> UC11
    HR --> UC13
    HR --> UC14
    HR --> UC15
"""

mmd_path = os.path.join(diagrams_dir, "use_case_diagram.mmd")
png_path = os.path.join(diagrams_dir, "use_case_diagram.png")

with open(mmd_path, "w", encoding="utf-8") as f:
    f.write(use_case_mmd)

cmd = f'npx -y @mermaid-js/mermaid-cli -i "{mmd_path}" -o "{png_path}" -b transparent -w 1400'
print("Re-rendering complete 15-Use Case diagram...")
res = subprocess.run(cmd, shell=True, capture_output=True, text=True)

if res.returncode == 0:
    print(f"SUCCESS: Generated updated {png_path}")
else:
    print(f"ERROR rendering use_case_diagram: {res.stderr}")
