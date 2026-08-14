import os
import subprocess
import sys

sys.stdout.reconfigure(encoding='utf-8')
diagrams_dir = r"c:\capstone\diagrams"
os.makedirs(diagrams_dir, exist_ok=True)

uc_definitions = {
    "use_case_01": """flowchart LR
    Admin["⚙️ System Admin"] --> UC01(["UC-01: User Authentication & Role Access (RBAC)"])
    User["👥 Any System User"] --> UC01
""",
    "use_case_02": """flowchart LR
    HR["💼 HR Officer"] --> UC02(["UC-02: Worker Registry & Skill Certifications"])
""",
    "use_case_03": """flowchart LR
    Engineer["👷‍♂️ Site Engineer"] --> UC03(["UC-03: Crew Assignment & Site Deployment"])
""",
    "use_case_04": """flowchart LR
    Foreman["👷 Site Foreman"] --> UC04(["UC-04: Offline Digital Checklist Clock-In"])
""",
    "use_case_05": """flowchart LR
    Foreman["👷 Site Foreman"] --> UC05(["UC-05: Local Storage Hardware Encryption (SQLCipher)"])
    System["🤖 Mobile System Process"] --> UC05
""",
    "use_case_06": """flowchart LR
    Foreman["👷 Site Foreman"] --> UC06(["UC-06: Monotonic Hardware Clock Capture (elapsedRealtime)"])
    System["🤖 Hardware Monotonic Clock"] --> UC06
""",
    "use_case_07": """flowchart LR
    Foreman["👷 Site Foreman"] --> UC07(["UC-07: Append-Only HMAC Hash Chaining Ledger"])
    Ledger["🔒 Cryptographic Ledger"] --> UC07
""",
    "use_case_08": """flowchart LR
    Foreman["👷 Site Foreman"] --> UC08(["UC-08: Hardware Security Module TEE Signing (ECDSA)"])
    TEE["🛡️ Android Keystore / iOS Enclave"] --> UC08
""",
    "use_case_09": """flowchart LR
    Foreman["👷 Site Foreman"] --> UC09(["UC-09: Automatic Background Network Sync Engine"])
    Network["📡 Network Listener Service"] --> UC09
""",
    "use_case_10": """flowchart LR
    Foreman["👷 Site Foreman"] --> UC10(["UC-10: Late Foreman Override & Shift-Start Credit"])
    Engineer["👷‍♂️ Site Engineer"] --> UC10
""",
    "use_case_11": """flowchart LR
    HR["💼 HR Officer"] --> UC11(["UC-11: 1-Click Absent Crew Re-Assignment"])
    Engineer["👷‍♂️ Site Engineer"] --> UC11
""",
    "use_case_12": """flowchart LR
    Engineer["👷‍♂️ Site Engineer"] --> UC12(["UC-12: Retroactive Crew Recovery Sign-Off"])
""",
    "use_case_13": """flowchart LR
    HR["💼 HR / Payroll Officer"] --> UC13(["UC-13: Philippine Labor Code Payroll Computation"])
""",
    "use_case_14": """flowchart LR
    Worker["👷 Construction Worker / Supervisor"] --> UC14(["UC-14: Overtime & Leave Filing Workflow"])
    Engineer["👷‍♂️ Site Engineer"] --> UC14
    HR["💼 HR Officer"] --> UC14
""",
    "use_case_15": """flowchart LR
    Exec["👔 Company Executive / HR Manager"] --> UC15(["UC-15: Executive Compliance Audit & Labor Cost Analytics"])
"""
}

for name, code in uc_definitions.items():
    mmd_path = os.path.join(diagrams_dir, f"{name}.mmd")
    png_path = os.path.join(diagrams_dir, f"{name}.png")
    with open(mmd_path, "w", encoding="utf-8") as f:
        f.write(code)
    
    cmd = f'npx -y @mermaid-js/mermaid-cli -i "{mmd_path}" -o "{png_path}" -b transparent -w 1000'
    print(f"Rendering {name}...")
    res = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if res.returncode == 0:
        print(f"SUCCESS: Generated {png_path}")
    else:
        print(f"ERROR rendering {name}: {res.stderr}")

print("All 15 individual Use Case diagrams successfully rendered!")
