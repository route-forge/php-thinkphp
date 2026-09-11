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

    // camelCase 动作名：自动路由时代 URL 大小写不敏感可命中，物化后需提醒
    public function batchImport()
    {
        return 'batchImport';
    }

    // 以下都不应被生成为端点：
    protected function secret() {}

    private function hidden() {}

    public static function staticish() {}

    public function _notAnAction() {}
}
