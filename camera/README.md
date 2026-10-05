# LiveCameraLinks camera

Official camera data structure.

- camera_index.json : master index
- road/             : road cameras
- route/            : route groups that reference camera IDs
- river/            : river cameras
- station/          : railway and station cameras
- city/             : city and downtown cameras
- airport/          : airport cameras
- parking/          : parking cameras
- tourism/          : tourism cameras

Route folders:

- route/national/
- route/expressway/
- route/travel/

Road sub-types such as national road, prefectural road, expressway,
mountain pass, snow road and traffic are managed by tags instead of
separate top-level folders.