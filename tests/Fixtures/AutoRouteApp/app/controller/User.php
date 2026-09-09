<?php

namespace app\controller;

class User
{
    public function read()
    {
        return 'read';
    }

    public function save()
    {
        return 'save';
    }

    public function index()
    {
        return 'index';
    }

    // 以下都不应被生成为端点：
    protected function secret() {}

    private function hidden() {}

    public static function staticish() {}

    public function _notAnAction() {}
}
