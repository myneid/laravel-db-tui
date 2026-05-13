<?php

namespace Myneid\LaravelDbTui\Tui;

enum Mode
{
    case Tables;   // Left panel focused — selecting a table
    case Data;     // Right panel focused — browsing rows
    case Row;      // Full row detail view (from Data)
    case Sql;      // Raw SQL editor + results
    case SqlRow;   // Full row detail view (from SQL results)
}
