Collects internet traffic for customers over a given date range and dumps it to a csv file. 

Optional Parameters:
  --end <date>      : Specifies the end date for the reporting period (e.g., --end 20/08/2025).
  --start <date>    : Specifies the start date for the reporting period (e.g., --start 15/07/2025).
                      When used with --end, this overrides the billing cycle logic.
  --silent          : Suppresses all progress and parameter output.


If the --end isn't specified then defaults to the previous calendar month.

If no --start <date> is specified then it checks the day of the month of each internet service and collects stats for the valid whole billing cycle prior


You'll need to change some splynx API keys to give read access including 

Tarrif plans - > internet -> view

Customers -> customer-> view

Customers -> customer information -> view

Customers -> internet services -> view

Customers -> traffic statistics -> view

Customers -> traffic counter -> view


CSV format is
"Customer ID",Name,Login,Email,Status,"Internet Plan Name","Internet Plan Status","Service ID","Service Start Date","Service End Date",IPv4,Router,Street,Town,"Total Upload (GB)","Total Download (GB)","Calculated Stats Start Date","Calculated Stats End Date"
