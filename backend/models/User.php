<?php

require_once __DIR__ . '/BaseModel.php';

class User extends BaseModel {
    protected $table = 'users';

    public function getDefaultUser() {
        $config = require __DIR__ . '/../config/config.php';
        return $this->findById($config['user']['default_user_id']);
    }
}
