UPDATE portal_users
SET username = LOWER(TRIM(username)),
    email = LOWER(TRIM(email))
WHERE BINARY username <> BINARY LOWER(TRIM(username))
   OR BINARY email <> BINARY LOWER(TRIM(email));
