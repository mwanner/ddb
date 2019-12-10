CREATE FOREIGN TABLE foreign_geopost (
       adr_geopost_id INTEGER NOT NULL,
       update_ts TIMESTAMP WITHOUT TIME ZONE NULL
)
  SERVER file_fdw_server
  OPTIONS (
    filename '/srv/sources/geopost_addresses.csv',
    format 'csv',
    delimiter E'\t',
    header 'true',
    encoding 'ISO-8859-1'
  );