<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleExport
{
    private const HEADERS = [
        'ID', 'VIN', 'Year', 'Make', 'Model', 'Trim',
        'Body Type', 'Color', 'Mileage (mi)', 'Transmission', 'Engine', 'Fuel Type',
        'Condition', 'Has Title', 'Title State', 'Status',
        'Seller Name', 'Seller Email', 'Created At',
    ];

    public function download(Builder $query, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Vehicles');

        // --- Header row ---
        $sheet->fromArray(self::HEADERS, null, 'A1');

        $lastCol = 'S'; // Column 19

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF334155'], // slate-700
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
            ],
        ]);

        // --- Data rows ---
        $row = 2;

        $query->chunk(200, function ($vehicles) use ($sheet, &$row) {
            foreach ($vehicles as $v) {
                $sheet->fromArray([
                    $v->id,
                    $v->vin,
                    $v->year,
                    $v->make,
                    $v->model,
                    $v->trim ?? '',
                    $v->body_type,
                    $v->color ?? '',
                    $v->mileage ?? '',
                    $v->transmission ?? '',
                    $v->engine ?? '',
                    $v->fuel_type ?? '',
                    $v->condition_light,
                    $v->has_title ? 'Yes' : 'No',
                    $v->title_state ?? '',
                    $v->status,
                    $v->seller?->name ?? '',
                    $v->seller?->email ?? '',
                    $v->created_at->format('Y-m-d H:i:s'),
                ], null, "A{$row}");

                $row++;
            }
        });

        // --- Auto-size columns ---
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // --- Stream download ---
        $writer = new Xlsx($spreadsheet);

        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }
}
