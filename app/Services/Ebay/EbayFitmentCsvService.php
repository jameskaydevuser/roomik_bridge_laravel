<?php

namespace App\Services\Ebay;

class EbayFitmentCsvService
{
    /**
     * Generate eBay fitment CSV content.
     *
     * @param string $itemNumber
     * @param array $fitmentRows
     * @param array $ebayItemIds
     * @return string
     */
    public function generate(string $itemNumber, array $fitmentRows, array $ebayItemIds): string
    {
        $handle = fopen('php://memory', 'r+');

        // CSV Header - written manually to ensure exact format without quotes if required
        fwrite($handle, "*Action(SiteID=eBayMotors|Country=US|Currency=USD|Version=1193),Item Number,Relationship,Relationship details\n");

        foreach ($ebayItemIds as $ebayItemId) {
            // Revise row
            fputcsv($handle, [
                'Revise',
                $ebayItemId,
                '',
                ''
            ]);

            // Fitment rows
            foreach ($fitmentRows as $row) {
                fputcsv($handle, [
                    '',
                    $itemNumber,
                    $row['relationship'],
                    $row['relationship_details']
                ]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
