<?php

/**
 * Splynx API Client Script
 *
 * This script retrieves a list of all active Splynx customers and their
 * internet services, along with their total upload and download traffic counters.
 * It generates a CSV file of the output data.
 *
 * This version of the script uses the BETWEEN attribute for a single, optimized
 * API call to retrieve all traffic data for a service in a given date range.
 */

// Include the configuration file
// IMPORTANT: Make sure to create a file named 'config.php' in the same directory.
// This file should contain:
// $splynxBaseUrl = 'https://your.splynx.url/api/2.0';
// $apiKey = 'your_splynx_api_key';
// $apiSecret = 'your_splynx_api_secret';
require_once 'config.php';

// Define the name of the output CSV file
$csvFileName = 'splynx_customers_traffic_data.csv';

/**
 * Splynx API Client Class
 *
 * This class provides a basic wrapper for interacting with the Splynx API.
 * It uses Basic Authentication for API requests.
 */
class SplynxApiClient
{
    private $apiUrl;
    private $apiKey;
    private $apiSecret;

    /**
     * Constructor
     *
     * @param string $apiUrl The base URL of your Splynx instance.
     * @param string $apiKey Your Splynx API Key.
     * @param string $apiSecret Your Splynx API Secret.
     */
    public function __construct($apiUrl, $apiKey, $apiSecret)
    {
        $this->apiUrl = rtrim($apiUrl, '/'); // Ensure no trailing slash
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /**
     * Makes a GET request to the Splynx API using Basic Authentication.
     *
     * @param string $path The API endpoint path.
     * @param array $params Optional query parameters.
     * @return array|null The decoded JSON response, or null on error.
     */
    public function get($path, $params = [])
    {
        $queryString = '';
        
        // Custom logic to handle the BETWEEN filter for the traffic counter endpoint
        if (isset($params['main_attributes']['date']['BETWEEN'])) {
            $dates = $params['main_attributes']['date']['BETWEEN'];
            unset($params['main_attributes']['date']); // Unset the complex array
            
            // Manually build the query string for the BETWEEN filter
            $filterString = sprintf(
                'filter[attribute]=date&filter[operator]=BETWEEN&filter[value][0]=%s&filter[value][1]=%s',
                urlencode($dates[0]),
                urlencode($dates[1])
            );
            
            // Build the rest of the query string as normal
            $remainingParams = http_build_query($params);
            $queryString = $filterString . '&' . $remainingParams;

        } else {
            $queryString = http_build_query($params);
        }

        $fullUrl = $this->apiUrl . '/' . ltrim($path, '/') . ($queryString ? '?' . $queryString : '');

        // Construct the Basic Authentication header
        $authHeader = 'Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret);

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authHeader,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Splynx-API-Client');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        } else if ($httpCode === 404 && strpos($path, 'customer-traffic-counter') !== false) {
            // Special case for the traffic counter endpoint, which returns 404 when no data is found.
            // We want to handle this gracefully by returning an empty array.
            return [];
        } else {
            // Echoing errors for CLI output for all other non-200 responses
            echo "API GET request to {$fullUrl} failed with HTTP code {$httpCode}: " . ($response ?: 'No response body') . "\n";
            return null;
        }
    }
}

// Parse command line arguments for start and end dates in UK format
$options = getopt('', ['start:', 'end:']);

$startDate = null;
$endDate = null;
$usingDefaultDates = false;

if (isset($options['start']) && isset($options['end'])) {
    try {
        $startDate = DateTime::createFromFormat('d/m/Y', $options['start']);
        $endDate = DateTime::createFromFormat('d/m/Y', $options['end']);

        if (!$startDate || !$endDate) {
            throw new Exception("Invalid date format. Please use DD/MM/YYYY.");
        }
        
        // Ensure end date is not before start date
        if ($startDate > $endDate) {
            throw new Exception("End date cannot be before start date.");
        }

    } catch (Exception $e) {
        die("Error: " . $e->getMessage() . "\n");
    }
} else {
    // No dates specified, default to the previous whole calendar month
    $usingDefaultDates = true;
    
    // Get the first day of the current month
    $currentMonth = new DateTime('first day of this month');
    
    // Subtract one day to get into the previous month
    $lastDayOfPreviousMonth = clone $currentMonth;
    $lastDayOfPreviousMonth->modify('-1 day');
    
    // Get the start and end of the previous month
    $startDate = new DateTime($lastDayOfPreviousMonth->format('Y-m-01'));
    $endDate = $lastDayOfPreviousMonth;
    
    echo "Warning: No date parameters specified. Defaulting to previous whole calendar month.\n";
    echo "Please use `--start DD/MM/YYYY --end DD/MM/YYYY` to specify a custom date range.\n";
}

echo "Initializing Splynx API client with Basic Authentication...\n";
$splynx = new SplynxApiClient($splynxBaseUrl, $apiKey, $apiSecret);

echo "\nRetrieving all active customers...\n";

// Define search parameters for active customers
$customerSearchParams = [
    'main_attributes' => [
        'status' => 'active'
    ]
];

// Make the API call to get customers with the 'active' status
$customers = $splynx->get(
    'admin/customers/customer', // Endpoint for listing customers
    $customerSearchParams
);

// Open the CSV file for writing
$csvFile = fopen($csvFileName, 'w');
if ($csvFile === false) {
    die("Error: Could not open the CSV file '{$csvFileName}' for writing.\n");
}

// Define the CSV header row
$csvHeader = [
    'Customer ID', 'Name', 'Login', 'Email', 'Status',
    'Internet Plan Name', 'Internet Plan Status',
    'IPv4', 'Router', 'Street', 'Town',
    'Total Upload (GB)', 'Total Download (GB)'
];
fputcsv($csvFile, $csvHeader);
echo "CSV file '{$csvFileName}' created with header row.\n";

if ($customers !== null) {
    if (!empty($customers)) {
        echo "\nFound " . count($customers) . " active customers.\n";
        
        echo "Fetching traffic for the period: " . $startDate->format('d/m/Y') . " to " . $endDate->format('d/m/Y') . "\n";
		
        // Initialize progress variables
        $totalCustomers = count($customers);
        $customersProcessed = 0;
        $nextProgressThreshold = 5;

        foreach ($customers as $customer) {
            $customersProcessed++;
            $currentProgress = floor(($customersProcessed / $totalCustomers) * 100);
            
            if ($currentProgress >= $nextProgressThreshold) {
                echo "Processing: " . $currentProgress . "% complete...\n";
                $nextProgressThreshold = $currentProgress + 5;
            }
            
            if (isset($customer['id'])) {
                $serviceEndpoint = 'admin/customers/customer/' . $customer['id'] . '/internet-services';
                $internetServices = $splynx->get($serviceEndpoint);

                if ($internetServices !== null && !empty($internetServices)) {
                    foreach ($internetServices as $service) {
                        if ($service['status'] === 'active') {
                            // Initialize variables for the CSV row
                            $planName = 'N/A';
                            $routerTitle = 'N/A';

                            // Fetch Internet Plan details
							if (isset($service['tariff_id'])) {
								$tariffEndpoint = 'admin/tariffs/internet/' . $service['tariff_id'];
								$tariffDetails = $splynx->get($tariffEndpoint);
								if($tariffDetails !== null & !empty($tariffDetails))
								{
									$planName = $tariffDetails['title'] ?? 'N/A';
								}
							}
                            
                            // Fetch Router details
							if (isset($service['router_id'])) {
								$routerEndpoint = 'admin/networking/routers/' . $service['router_id'];
								$routerDetails = $splynx->get($routerEndpoint);
								if($routerDetails !== null & !empty($routerDetails))
								{
									$routerTitle = $routerDetails[ 'title'] ?? 'N/A';
								}
							}

                            // Fetch address information from the geo-internet-service endpoint
                            $geoServiceEndpoint = 'admin/customers/customer/' . $customer['id'] . '/geo-internet-service--' . $service['id'];
                            $geoService = $splynx->get($geoServiceEndpoint);

                            $installStreetDisplay = 'N/A';
                            $installTownDisplay = 'N/A';

                            if ($geoService && isset($geoService['address'])) {
                                $address = $geoService['address'];
                                $addressParts = explode(',', $address);
                                
                                if (count($addressParts) > 1) {
                                    $installStreetDisplay = trim($addressParts[0]);
                                    $installTownDisplay = trim($addressParts[1]);
                                } else {
                                    $installStreetDisplay = trim($address);
                                    // Fallback to customer city if no town in address
                                    $installTownDisplay = $customer['city'] ?? 'N/A';
                                }
                            } else {
                                // Fallback to customer address if geo data is not available
                                $installStreetDisplay = $customer['street_1'] ?? 'N/A';
                                $installTownDisplay = $customer['city'] ?? 'N/A';
                            }
                            
                            // Initialize byte counters for the date range
                            $totalUploadBytes = 0;
                            $totalDownloadBytes = 0;
                            
                            // Use the singular endpoint with the BETWEEN filter for a single, optimized API call
                            $trafficParams = [
                                'main_attributes' => [
                                    'service_id' => $service['id'],
                                    'date' => [
                                        'BETWEEN' => [
                                            $startDate->format('Y-m-d'),
                                            $endDate->format('Y-m-d')
                                        ]
                                    ]
                                ]
                            ];
                            $trafficCounters = $splynx->get('admin/customers/customer-traffic-counter', $trafficParams);
                            
                            if ($trafficCounters !== null && !empty($trafficCounters)) {
                                foreach ($trafficCounters as $counter) {
                                    $totalUploadBytes += $counter['up'] ?? 0;
                                    $totalDownloadBytes += $counter['down'] ?? 0;
                                }
                            }

                            // Convert bytes to gigabytes and format to 2 decimal places
                            $totalUploadGB = number_format($totalUploadBytes / 1073741824, 2);
                            $totalDownloadGB = number_format($totalDownloadBytes / 1073741824, 2);

                            // Prepare data for the CSV row
                            $rowData = [
                                $customer['id'] ?? '',
                                $customer['name'] ?? '',
                                $customer['login'] ?? '',
                                $customer['email'] ?? '',
                                $customer['status'] ?? '',
                                $planName,
                                $service['status'] ?? '',
                                $service['ipv4'] ?? '',
                                $routerTitle,
                                $installStreetDisplay,
                                $installTownDisplay,
                                $totalUploadGB,
                                $totalDownloadGB
                            ];
                            
                            // Write the row to the CSV file
                            fputcsv($csvFile, $rowData);
                        }
                    }
                }
            }
        }
    } else {
        echo "No active customers found.\n";
    }
} else {
    echo "Failed to retrieve customer data. Please check your API Key, Secret, and Splynx API URL.\n";
}

// Close the CSV file
fclose($csvFile);
echo "\nData successfully written to '{$csvFileName}'.\n";

?>
