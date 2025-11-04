<?php

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option;

enum IndexActionOptionsExportExportTypeEnum: string
{
    case XLSX = 'xlsx';
    case CSV = 'csv';
}
