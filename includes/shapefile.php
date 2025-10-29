<?php
/**
 * Simple Shapefile Reader
 * Based on: https://github.com/paulgb/simple-shapefile
 * Modified for NWS Location Codes plugin
 */

class ShapeFile {
    private $shp_file;
    private $dbf_file;
    private $shp_handle;
    private $dbf_handle;
    private $shape_type;
    private $record_count;
    private $current_record = 0;
    private $dbf_fields = array();
    
    public function __construct($base_file) {
        // Support both .shp extension and no extension
        $base_file = preg_replace('/\.shp$/', '', $base_file);
        
        $this->shp_file = $base_file . '.shp';
        $this->dbf_file = $base_file . '.dbf';
        
        if (!file_exists($this->shp_file)) {
            throw new Exception("Shapefile not found: " . $this->shp_file);
        }
        
        if (!file_exists($this->dbf_file)) {
            throw new Exception("DBF file not found: " . $this->dbf_file);
        }
        
        $this->shp_handle = fopen($this->shp_file, 'rb');
        $this->dbf_handle = fopen($this->dbf_file, 'rb');
        
        $this->readShapefileHeader();
        $this->readDBFHeader();
    }
    
    private function readShapefileHeader() {
        // Read shapefile header (100 bytes)
        $header = fread($this->shp_handle, 100);
        
        // File code (bytes 0-3, big endian)
        $file_code = unpack('N', substr($header, 0, 4))[1];
        if ($file_code !== 9994) {
            throw new Exception("Invalid shapefile format");
        }
        
        // Shape type (bytes 32-35, little endian)
        $this->shape_type = unpack('V', substr($header, 32, 4))[1];
        
        // File length is in bytes 24-27 (big endian, in 16-bit words)
        $file_length_words = unpack('N', substr($header, 24, 4))[1];
        $file_length_bytes = $file_length_words * 2;
        
        // Calculate approximate record count
        $this->record_count = ($file_length_bytes - 100) / 100; // Rough estimate
    }
    
    private function readDBFHeader() {
        // Read DBF header
        $header = fread($this->dbf_handle, 32);
        
        // Get number of records (bytes 4-7, little endian)
        $this->record_count = unpack('V', substr($header, 4, 4))[1];
        
        // Get header length (bytes 8-9, little endian)
        $header_length = unpack('v', substr($header, 8, 2))[1];
        
        // Read field descriptors
        $field_count = ($header_length - 33) / 32;
        
        for ($i = 0; $i < $field_count; $i++) {
            $field_desc = fread($this->dbf_handle, 32);
            
            // Field name (bytes 0-10, null-terminated)
            $field_name = trim(substr($field_desc, 0, 11), "\x00");
            
            // Field type (byte 11)
            $field_type = substr($field_desc, 11, 1);
            
            // Field length (byte 16)
            $field_length = ord(substr($field_desc, 16, 1));
            
            $this->dbf_fields[] = array(
                'name' => $field_name,
                'type' => $field_type,
                'length' => $field_length
            );
        }
        
        // Skip terminator (0x0D)
        fread($this->dbf_handle, 1);
    }
    
    public function getRecord() {
        if ($this->current_record >= $this->record_count) {
            return false;
        }
        
        // Read shapefile record
        $shp_data = $this->readShapeRecord();
        
        // Read DBF record
        $dbf_data = $this->readDBFRecord();
        
        if ($shp_data === false || $dbf_data === false) {
            return false;
        }
        
        $this->current_record++;
        
        return array(
            'shp' => $shp_data,
            'dbf' => $dbf_data
        );
    }
    
    private function readShapeRecord() {
        // Read record header (8 bytes)
        $record_header = fread($this->shp_handle, 8);
        if (strlen($record_header) < 8) {
            return false;
        }
        
        // Record number (big endian)
        $record_number = unpack('N', substr($record_header, 0, 4))[1];
        
        // Content length in 16-bit words (big endian)
        $content_length_words = unpack('N', substr($record_header, 4, 4))[1];
        $content_length_bytes = $content_length_words * 2;
        
        // Read record content
        $content = fread($this->shp_handle, $content_length_bytes);
        
        // Shape type (little endian)
        $shape_type = unpack('V', substr($content, 0, 4))[1];
        
        // Parse based on shape type
        switch ($shape_type) {
            case 0: // Null shape
                return array('type' => 'null');
            
            case 5: // Polygon
                return $this->parsePolygon($content);
            
            case 3: // PolyLine
                return $this->parsePolyLine($content);
            
            case 1: // Point
                return $this->parsePoint($content);
            
            default:
                return array('type' => 'unknown', 'shape_type' => $shape_type);
        }
    }
    
    private function parsePolygon($content) {
        // Box (bytes 4-35, 4 doubles)
        $box = array(
            'xmin' => unpack('d', substr($content, 4, 8))[1],
            'ymin' => unpack('d', substr($content, 12, 8))[1],
            'xmax' => unpack('d', substr($content, 20, 8))[1],
            'ymax' => unpack('d', substr($content, 28, 8))[1]
        );
        
        // Number of parts (bytes 36-39)
        $num_parts = unpack('V', substr($content, 36, 4))[1];
        
        // Number of points (bytes 40-43)
        $num_points = unpack('V', substr($content, 40, 4))[1];
        
        // Parts array (starting at byte 44)
        $parts = array();
        for ($i = 0; $i < $num_parts; $i++) {
            $parts[] = unpack('V', substr($content, 44 + ($i * 4), 4))[1];
        }
        
        // Points array (starting after parts)
        $points_start = 44 + ($num_parts * 4);
        $points = array();
        
        for ($i = 0; $i < $num_points; $i++) {
            $offset = $points_start + ($i * 16);
            $x = unpack('d', substr($content, $offset, 8))[1];
            $y = unpack('d', substr($content, $offset + 8, 8))[1];
            
            $points[] = array('x' => $x, 'y' => $y);
        }
        
        return array(
            'type' => 'polygon',
            'box' => $box,
            'num_parts' => $num_parts,
            'num_points' => $num_points,
            'parts' => $parts,
            'points' => $points
        );
    }
    
    private function parsePolyLine($content) {
        // PolyLine has same structure as Polygon
        $result = $this->parsePolygon($content);
        $result['type'] = 'polyline';
        return $result;
    }
    
    private function parsePoint($content) {
        // X coordinate (bytes 4-11)
        $x = unpack('d', substr($content, 4, 8))[1];
        
        // Y coordinate (bytes 12-19)
        $y = unpack('d', substr($content, 12, 8))[1];
        
        return array(
            'type' => 'point',
            'x' => $x,
            'y' => $y
        );
    }
    
    private function readDBFRecord() {
        // Read record marker (1 byte)
        $marker = fread($this->dbf_handle, 1);
        
        if ($marker === false || strlen($marker) === 0) {
            return false;
        }
        
        // Check if deleted (0x2A) or end of file (0x1A)
        if ($marker === chr(0x2A)) {
            // Deleted record, skip it
            $record_length = $this->getRecordLength();
            fread($this->dbf_handle, $record_length - 1);
            return $this->readDBFRecord(); // Read next record
        }
        
        if ($marker === chr(0x1A)) {
            return false; // End of file
        }
        
        // Read record data
        $record = array();
        
        foreach ($this->dbf_fields as $field) {
            $value = fread($this->dbf_handle, $field['length']);
            
            // Trim and convert based on type
            $value = trim($value);
            
            switch ($field['type']) {
                case 'N': // Numeric
                case 'F': // Float
                    $value = $value !== '' ? floatval($value) : null;
                    break;
                case 'L': // Logical
                    $value = in_array(strtoupper($value), array('T', 'Y', '1'));
                    break;
                case 'D': // Date
                    // Format: YYYYMMDD
                    if (strlen($value) === 8) {
                        $value = substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
                    }
                    break;
                default: // Character and others
                    $value = $value;
            }
            
            $record[$field['name']] = $value;
        }
        
        return $record;
    }
    
    private function getRecordLength() {
        $length = 1; // Deletion marker
        foreach ($this->dbf_fields as $field) {
            $length += $field['length'];
        }
        return $length;
    }
    
    public function __destruct() {
        if ($this->shp_handle) {
            fclose($this->shp_handle);
        }
        if ($this->dbf_handle) {
            fclose($this->dbf_handle);
        }
    }
}
