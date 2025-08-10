<?php

namespace App;

use PDO;

abstract class BaseModel
{
    protected PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }
}