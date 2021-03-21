<?php 
    require('config/config.php');

    class DB_Login{

        private $pdo;

        public function __construct($_db_server, $_db_database, $_db_username, $_db_password){
            $this->pdo = new PDO("mysql:host=".$_db_server."; dbname=".$_db_database.";", $_db_username, $_db_password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            // $create_db_query = "CREATE DATABASE IF NOT EXISTS ".$_db_database.";";
            // $createDbStmt = $this->pdo->prepare($create_db_query);
            // $createDbStmt->execute();
            $user_table_query = "CREATE TABLE IF NOT EXISTS users ( id INT NOT NULL AUTO_INCREMENT, username VARCHAR(50) NOT NULL, password_hash VARCHAR(255) NOT NULL, is_admin TINYINT NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1, PRIMARY KEY(id));";
            $userTableStmt = $this->pdo->prepare($user_table_query);
            $userTableStmt->execute();
            if ($this->GetActiveUserCount() < 1){
                $this->AddUser("admin","password",true);
            }
            //"CREATE TABLE IF NOT EXISTS users"
        }

        public function AddUser($_username, $_password, $_is_admin){
            $user_id = $this->GetUserId($_username);
            if (!is_null($user_id)){
                return false;
            }
            $_admin = 0;
            if ($_is_admin){
                $_admin = 1;
            }
            $insertQuery = "INSERT INTO users ( username, password_hash, is_admin ) VALUES ( :username, :password_hash, :is_admin );";
            $stmt = $this->pdo->prepare($insertQuery);
            $stmt->bindParam(":username", $_username);
            $hashed_password = password_hash($_password, PASSWORD_BCRYPT);
            $stmt->bindParam(":password_hash", $hashed_password);
            $stmt->bindParam(":is_admin", $_admin);
            return $stmt->execute();
        }

        public function GetActiveUserCount(){
            $countQuery = "SELECT COUNT(id) FROM users WHERE active = 1";
            $stmt = $this->pdo->prepare($countQuery);
            if ($stmt->execute()){
                return $stmt->fetchColumn();
            }
            return 0;
        }

        public function CheckPassword($_username, $_password){
            $selectQuery = "SELECT password_hash FROM users WHERE username = :username AND active = 1";
            $stmt = $this->pdo->prepare($selectQuery);
            $stmt->bindParam(":username",$_username);
            $stmt->execute();
            if ($stmt->rowCount() > 0){
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    if (password_verify($_password, $row["password_hash"])){
                        return true;
                    }
                }
            }
            return false;
        }

        public function ChangePassword($_username, $_curr_pass, $_new_pass){
            if ($this->CheckPassword($_username, $_curr_pass)){
                $hashed_password = password_hash($_new_pass, PASSWORD_BCRYPT);
                $updateQuery = "UPDATE users SET password_hash = :password_hash WHERE id = :id;";
                $updateQuery->bindParam(':password_hash', $hashed_password);

            }
        }

        public function IsAdmin($_username){
            $selectQuery = "SELECT is_admin FROM users WHERE username = :username";
            $stmt = $this->pdo->prepare($selectQuery);
            $stmt->bindParam(":username",$_username);
            $stmt->execute();
            if ($stmt->rowCount() > 0){
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    return $row["is_admin"];
                }
            }
        }

        public function ToggleAdmin($_userid){
            $toggleQuery = "UPDATE users SET is_admin = 1 - is_admin WHERE id = :id;";
            $stmt = $this->pdo->prepare($toggleQuery);
            $stmt->bindParam(":id", $_userid);
            return $stmt->execute();
        }

        public function ToggleActive($_userid){
            $toggleQuery = "UPDATE users SET active = 1 - active WHERE id = :id;";
            $stmt = $this->pdo->prepare($toggleQuery);
            $stmt->bindParam(":id", $_userid);
            return $stmt->execute();
        }

        public function GetUserId($_username){
            $selectQuery = "SELECT id FROM users WHERE username = :username";
            $stmt = $this->pdo->prepare($selectQuery);
            $stmt->bindParam(":username",$_username);
            $stmt->execute();
            if ($stmt->rowCount() > 0){
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    return $row["id"];
                }
            }
            return null;
        }

        public function GetUsers(){
            $selectQuery = "SELECT id, username, is_admin, active FROM users";
            $stmt = $this->pdo->prepare($selectQuery);
            $stmt->execute();
            $users = array();
            if ($stmt->rowCount() > 0){
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    $id = $row["id"];
                    $user = $row["username"];
                    $is_admin = $row["is_admin"];
                    $active = $row["active"];
                    array_push($users, array("id"=>$id, "username"=>$user, "is_admin"=>$is_admin, "active"=>$active));
                }
            }
            return $users;
        }

        public function DeleteUser($_userId){
            $selectQuery = "DELETE FROM users WHERE id = :id";
            $stmt = $this->pdo->prepare($selectQuery);
            $stmt->bindParam(":id",$_userId);
            return $stmt->execute();
        }
    }
?>
