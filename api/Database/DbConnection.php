<?php

namespace api\Database\DbConnection;

class DbConnection
{
    private string $host;
    private string $database;
    private string $username;
    private string $password;
    private $connection;

    public function __construct(string $host, string $database, string $username, string $password)
    {
        $this->host = $host;
        $this->database = $database;
        $this->username = $username;
        $this->password = $password;
        $this->connect();
    }

    private function connect()
    {
        error_log(
            "Host: {$this->host} | Database: {$this->database} | Username: {$this->username} | Password: {$this->password}"
        );
        $this->connection = mysqli_connect($this->host, $this->username, $this->password, $this->database);

        if (!$this->connection) {
            die("Falha na conexão com o banco: " . mysqli_connect_error());
        }

        mysqli_set_charset($this->connection, "utf8mb4");
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function closeConnection()
    {
        if ($this->connection) {
            mysqli_close($this->connection);
        }
    }
}
