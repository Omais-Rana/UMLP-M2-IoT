# Exercise 1 — Modulus verification
P = 20
k = 7
m = 26

C = (P + k) % m
print(C)

# Exercise 2 — Modular Addition Encryption
k, m = 7, 26
plaintexts = [0, 1, 5, 10, 19, 20, 25]

for P in plaintexts:
    C = (P + k) % m
    print(f"P={P} -> C={C}")

# Exercise 3 — Encrypt/Decrypt functions
def encrypt_add(P, k, m):
    return (P + k) % m

def decrypt_add(C, k, m):
    return (C - k) % m

# Verification
k, m = 7, 26
C = 1
P = decrypt_add(C, k, m)
print(f"Recovered P = {P}")

C_check = encrypt_add(P, k, m)
print(f"Re-encrypted C = {C_check}")

# Exercise 4 — Modular Multiplication
P, k, m = 20, 5, 26
C = (P * k) % m
print(f"C = {C}")

print("\nAll values of P from 1 to 25:")
seen = {}
for P in range(1, 26):
    C = (P * k) % m
    print(f"P={P} -> C={C}")
    if C in seen:
        print(f"  Collision! Same C as P={seen[C]}")
    seen[C] = P

# Exercise 5 — Finding the Multiplicative Inverse
k = 5
m = 26

for x in range(m):
    if (k * x) % m == 1:
        print(f"The inverse of {k} mod {m} is: {x}")
        break

# Recover P
C = 22
k_inv = 21
P = (C * k_inv) % m
print(f"Recovered P = {P}")

# Exercise 6 — Inverse Table for Multiple (k, m) Pairs
import math

pairs = [(5, 26), (7, 26), (2, 26), (13, 26), (3, 26), (5, 25)]

for k, m in pairs:
    g = math.gcd(k, m)
    inverse = None
    if g == 1:
        for x in range(m):
            if (k * x) % m == 1:
                inverse = x
                break
    print(f"k={k}, m={m}, gcd={g}, inverse={inverse if inverse is not None else 'None'}")

# Exercise 7 — Collision Demonstration
m = 26
k = 2

print("C values for P=1 to 13:")
for P in range(1, 14):
    C = (k * P) % m
    print(f"P={P} -> C={C}")

E1 = (k * 1) % m
E14 = (k * 14) % m
print(f"\nE(1) = {E1}, E(14) = {E14}")
print(f"E(1) == E(14)? {E1 == E14}")

import math
print(f"gcd(2, 26) = {math.gcd(2, 26)}")

# Exercise 8 — Full Multiplication Cipher
def encrypt_mult(P, k, m):
    return (P * k) % m

def decrypt_mult(C, k_inverse, m):
    return (C * k_inverse) % m

m = 26
k = 5
k_inverse = 21  # manually calculated

P = 20
C = encrypt_mult(P, k, m)
print(f"Encrypted: C = {C}")

P_recovered = decrypt_mult(C, k_inverse, m)
print(f"Decrypted: P = {P_recovered}")

assert P == P_recovered, "Mismatch!"
print("Success: P_recovered matches original P")

# Exercise 9 — Finding All Valid Keys
import math

m = 26
valid_keys = [k for k in range(1, m) if math.gcd(k, m) == 1]
print(f"Valid keys: {valid_keys}")
print(f"Number of valid keys: {len(valid_keys)}")

invalid_keys = [k for k in range(1, m) if math.gcd(k, m) != 1]
print(f"Invalid keys: {invalid_keys}")

print("\nKey -> Inverse pairs:")
for k in valid_keys:
    for x in range(m):
        if (k * x) % m == 1:
            print(f"k={k} -> k^-1={x}")
            break

# Exercise 10 — Alice and Bob Scenario
import math

m = 26
k = 7

print(f"gcd(7,26) = {math.gcd(k, m)}")

k_inv = None
for x in range(m):
    if (k * x) % m == 1:
        k_inv = x
        break
print(f"k^-1 = {k_inv}")

P = 19
C = (P * k) % m
print(f"Alice encrypts P={P} -> C={C}")

P_recovered = (C * k_inv) % m
print(f"Bob decrypts C={C} -> P={P_recovered}")

# Exercise 13 — Modular Exponentiation
P, k, m = 3, 4, 26
C = pow(P, k, m)
print(f"C = {C}")

print("\nC values for P=1 to 25, k=4:")
for P in range(1, 26):
    C = pow(P, 4, m)
    print(f"P={P} -> C={C}")

# Exercise 14 — Complete System (Part 2 full implementation)
import math

def mod_inverse(k, m):
    for x in range(1, m):
        if (k * x) % m == 1:
            return x
    return None

def encrypt(P, k, m):
    return (P * k) % m

def decrypt(C, k_inv, m):
    return (C * k_inv) % m

m = int(input("Enter modulus m: "))
k = int(input("Enter key k: "))

if math.gcd(k, m) != 1:
    print("Invalid key: gcd(k, m) must be 1.")
else:
    k_inv = mod_inverse(k, m)
    print(f"Inverse of {k} mod {m} is {k_inv}")

    P = int(input("Enter plaintext P: "))
    C = encrypt(P, k, m)
    print(f"Ciphertext C = {C}")

    P_recovered = decrypt(C, k_inv, m)
    print(f"Recovered plaintext = {P_recovered}")

    assert P == P_recovered, "Decryption failed!"
    print("Decryption verified successfully.")