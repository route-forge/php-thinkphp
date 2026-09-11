<?php

namespace app\controller;

/**
 * action_suffix='View' 夹具：think 用「URL 段 + suffix」命中方法，
 * 故 *View 方法可达于短形式 URL，非 *View 方法没有可达 URL。
 */
class Report
{
    public function listView()
    {
        return 'list';
    }

    public function indexView()
    {
        return 'index';
    }

    // 不以 View 结尾 → 当前 action_suffix 下无可达 URL
    public function export()
    {
        return 'export';
    }
}
