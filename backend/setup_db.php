<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1', 'root', 'oSphinx107!');
    echo "MySQL connection successful\n";
    
    // Create database if it doesn't exist
    $pdo->exec('CREATE DATABASE IF NOT EXISTS hr_system');
    echo "Database 'hr_system' created or already exists\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
