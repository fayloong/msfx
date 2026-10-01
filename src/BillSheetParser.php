<?php
/**
 * xlsx 导入表的解析：读表 → 按单号分组成"一单一条"。
 *
 * 批发与门店两个导入端点共用这一份——分组规则（同单号多行合并、取第一个非空的日期/类型/往来单位、
 * 一行一个码与逗号分隔两种写法都认、全空行跳过）是**页面上写死的格式约定**，各写一份的话，
 * 用户按模板填的同一个文件在两个分支里会被读成不同的单据。
 *
 * 本类只管"读成什么"，不管"合不合法"：类型白名单、往来单位是否必填、日期是否超期都归调用方
 * （批发在端点里，门店在 `App\RetailManualEntry::prepare()`）——两边的规则本就不同，硬并成一份
 * 反而要往这里塞分支。
 */
namespace App;

use PhpOffice\PhpSpreadsheet\IOFactory;

class BillSheetParser
{
    /**
     * 解析 xlsx（列序写死：日期 | 单号 | 单据类型 | 往来单位名称 | 追溯码，第一行为表头）。
     *
     * @return array{groups: array<string, array{rq:string, bill_type:string, ent_name:string, codes:array<int,string>, lines:array<int,int>}>, errors: array<int,string>}
     *         groups 以单号为键（同单号的行合成一条，`lines` 记它来自哪几行，报错时指认用）；
     *         errors 是解析期就能判定的错误（行号可指认），与业务校验的错误由调用方各自追加
     * @throws \Exception 文件读不出、或没有数据行
     */
    public static function parse(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) < 2) {
            throw new \Exception('文件中没有数据行');
        }

        // 跳过表头
        $dataRows = array_slice($rows, 1);

        $groups = [];
        $errors = [];

        foreach ($dataRows as $index => $row) {
            $lineNum = $index + 2;
            $rq = trim($row[0] ?? '');
            $djbh = trim($row[1] ?? '');
            $billType = trim($row[2] ?? '');
            $entName = trim($row[3] ?? '');
            $traceCodes = trim($row[4] ?? '');
            // 一行一个追溯码的写法也认：换行归一后转逗号
            $traceCodes = preg_replace('/\r\n|\r/', "\n", $traceCodes);
            $traceCodes = preg_replace('/\n+/', ',', $traceCodes);
            $traceCodes = trim($traceCodes, ',');

            // 跳过全空行
            if ($rq === '' && $djbh === '' && $billType === '' && $entName === '' && $traceCodes === '') {
                continue;
            }

            if ($djbh === '') {
                $errors[] = "第 {$lineNum} 行: 单号为空，无法分组";
                continue;
            }

            if (!isset($groups[$djbh])) {
                $groups[$djbh] = [
                    'rq' => '',
                    'bill_type' => '',
                    'ent_name' => '',
                    'codes' => [],
                    'lines' => [],
                ];
            }

            // 取第一个非空的日期、单据类型和往来单位
            if ($groups[$djbh]['rq'] === '' && $rq !== '') {
                $groups[$djbh]['rq'] = $rq;
            }
            if ($groups[$djbh]['bill_type'] === '' && $billType !== '') {
                $groups[$djbh]['bill_type'] = $billType;
            }
            if ($groups[$djbh]['ent_name'] === '' && $entName !== '') {
                $groups[$djbh]['ent_name'] = $entName;
            }

            if ($traceCodes !== '') {
                $groups[$djbh]['codes'][] = $traceCodes;
            }
            $groups[$djbh]['lines'][] = $lineNum;
        }

        return ['groups' => $groups, 'errors' => $errors];
    }

    /**
     * 分组的行号串（错误消息里指认用）：'第 3、4 行'。
     *
     * @param array{rq:string, bill_type:string, ent_name:string, codes:array, lines:array<int,int>} $group
     */
    public static function lineLabel(array $group): string
    {
        return '第 ' . implode('、', $group['lines']) . ' 行';
    }
}
