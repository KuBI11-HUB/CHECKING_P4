<?php
/**
 * Run this once locally (open it in your browser through XAMPP/your local
 * server) to generate a real bcrypt hash for your seed admin password.
 * Paste the output into granted_schema.sql in place of
 * PASTE_GENERATED_HASH_HERE, then delete this file — never leave a
 * hash-generator script on a live server.
 */

$password = 'admin123'; // change this if you want a different admin password

echo password_hash($password, PASSWORD_DEFAULT);
