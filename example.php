<?php

include_once("Backend/Backend.class.php");
include_once("Backend/Backend_ORM.class.php");

define(DB_HOST, "localhost");
define(DB_NAME, 'db_name');
define(DB_USER, 'username');
define(DB_PASS, 'password');

// Initiate connection
$backend = new Backend(DB_HOST, DB_NAME, DB_USER, DB_PASS);

// Retreive All Users
$users = new Backend_ORM('users');
$users->get();
while ($user = $users->next())
{
    // Field
    print $user->name;
    
    // As Array
    print_r($user->get());
}

           
// Retreive Example User
$user = new Backend_ORM('users');
$user->get(1);
print $user->name;


// Create User
$user = new Backend_ORM('users');
$user->name = "Test User";
$user_id = $user->save();

// Update User
$user = new Backend_ORM('users');
$user->get(1);
$user->name = "Updated Test User";
$user->save();



// OTHER FEATURES

// Conditions (can be multiple
$user = new Backend_ORM('users');
$user->cond("name", "Test User");
// or
$user->cond("user_id > 10");

// Ordering
$user = new Backend_ORM('users');
$user->order("name", "asc");

// Limit
$user = new Backend_ORM('users');
$user->limit(10);

// Join Table
$user = new Backend_ORM('users');
$user->join('user_details'); // joins by default on PK
$user->get(1);
print $user->age;


// Cache query
$user = new Backend_ORM('users');
/* Insert complex query param's */
$user->get(null, 360); // Cache for 5 minutes

?>
