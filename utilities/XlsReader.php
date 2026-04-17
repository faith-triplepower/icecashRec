<?php
// ============================================================
// utilities/XlsReader.php
// Minimal pure-PHP reader for binary .xls files (BIFF8 / Excel
// 97-2003). Handles OLE2 compound document navigation, Shared
// String Table extraction, and the common cell record types
// (LABELSST, NUMBER, RK, MULRK, LABEL, BLANK, FORMULA, BOOLERR).
//
// Scope: reads the FIRST worksheet as a grid of strings/numbers
// and returns it as an array of row-arrays. Does NOT handle
// formatting, formulas beyond cached values, charts, macros, or
// non-standard cell record types.
//
// References:
//   - OpenOffice.org "Microsoft Excel File Format" spec
//   - [MS-XLS]: Excel Binary File Format Structure
//   - [MS-CFB]: Compound File Binary File Format
// ============================================================

class XlsReader {

    // OLE2 constants
    const OLE_MAGIC           = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    const FREESECT            = 0xFFFFFFFF;
    const ENDOFCHAIN          = 0xFFFFFFFE;
    const FATSECT             = 0xFFFFFFFD;
    const DIFSECT             = 0xFFFFFFFC;

    // BIFF record types (little subset of what Excel defines)
    const BIFF_BOF            = 0x0809;
    const BIFF_EOF            = 0x000A;
    const BIFF_BOUNDSHEET     = 0x0085;
    const BIFF_SST            = 0x00FC;
    const BIFF_CONTINUE       = 0x003C;
    const BIFF_LABELSST       = 0x00FD;
    const BIFF_LABEL          = 0x0204;
    const BIFF_NUMBER         = 0x0203;
    const BIFF_RK             = 0x027E;
    const BIFF_MULRK          = 0x00BD;
    const BIFF_MULBLANK       = 0x00BE;
    const BIFF_BLANK          = 0x0201;
    const BIFF_FORMULA        = 0x0006;
    const BIFF_STRING         = 0x0207;
    const BIFF_BOOLERR        = 0x0205;
    const BIFF_DIMENSIONS     = 0x0200;
    const BIFF_ROW            = 0x0208;
    const BIFF_INDEX          = 0x020B;
    const BIFF_CODEPAGE       = 0x0042;

    private $raw;                 // full file contents
    private $sector_size;         // usually 512
    private $mini_sector_size;    // usually 64
    private $mini_cutoff;         // usually 4096
    private $fat      = array();  // main FAT as array of sector pointers
    private $mini_fat = array();  // mini FAT as array
    private $dir_entries = array();
    private $root_mini_stream = ''; // mini stream extracted from root entry
    private $workbook_stream = '';
    private $sst = array();
    private $sheets = array();    // parsed sheet data as row arrays

    // SST reader state (used while parsing the Shared String Table
    // across an SST record plus its trailing CONTINUE records).
    private $sst_data         = '';    // concatenated payload (SST header stripped)
    private $sst_pos          = 0;     // byte offset within $sst_data
    private $sst_boundary_set = array(); // offset => true, O(1) membership
    private $sst_boundaries   = array(); // sorted list of boundary offsets
    private $sst_bnd_ptr      = 0;     // next-unseen index into $sst_boundaries

    // OLE2 header-derived fields (set by parseHeader)
    private $_hdr_first_dir_sect = 0;
    private $_hdr_first_mini_fat = 0;
    private $_hdr_num_mini_fat   = 0;
    private $_hdr_num_fat_sect   = 0;
    private $_hdr_first_difat    = 0;
    private $_hdr_num_difat      = 0;
    private $_difat              = array();

    public function __construct($filepath) {
        if (!file_exists($filepath)) throw new Exception("File not found: $filepath");
        $this->raw = file_get_contents($filepath);
        if (strlen($this->raw) < 512) throw new Exception('File too small to be a valid .xls');
        if (substr($this->raw, 0, 8) !== self::OLE_MAGIC) {
            throw new Exception('Not a valid OLE2 compound document (.xls)');
        }
        $this->parseHeader();
        $this->parseFat();
        $this->parseDirectory();
        $this->extractWorkbookStream();
        $this->parseBiffRecords();
    }

    /**
     * Return rows from the first sheet as an array of arrays.
     * Each row is indexed by column number (0-based); blanks are empty strings.
     */
    public function getFirstSheetRows() {
        if (empty($this->sheets)) return array();
        $sheet = reset($this->sheets);
        // Convert sparse [row][col] => val into dense rows
        if (empty($sheet)) return array();
        $max_row = max(array_keys($sheet));
        $max_col = 0;
        foreach ($sheet as $row_cells) {
            if (!empty($row_cells)) {
                $m = max(array_keys($row_cells));
                if ($m > $max_col) $max_col = $m;
            }
        }
        $rows = array();
        for ($r = 0; $r <= $max_row; $r++) {
            $row = array();
            for ($c = 0; $c <= $max_col; $c++) {
                $row[] = isset($sheet[$r][$c]) ? $sheet[$r][$c] : '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    // ─────────────────────────────────────────────────────────
    // OLE2 HEADER
    // ─────────────────────────────────────────────────────────
    private function parseHeader() {
        $hdr = substr($this->raw, 0, 512);
        $sec_shift      = $this->u16($hdr, 0x1E);
        $mini_shift     = $this->u16($hdr, 0x20);
        $this->sector_size      = 1 << $sec_shift;       // usually 512
        $this->mini_sector_size = 1 << $mini_shift;      // usually 64

        $num_fat_sect   = $this->u32($hdr, 0x2C);
        $first_dir_sect = $this->u32($hdr, 0x30);
        $this->mini_cutoff = $this->u32($hdr, 0x38);     // usually 4096
        $first_mini_fat = $this->u32($hdr, 0x3C);
        $num_mini_fat   = $this->u32($hdr, 0x40);
        $first_difat    = $this->u32($hdr, 0x44);
        $num_difat      = $this->u32($hdr, 0x48);

        $this->_hdr_first_dir_sect = $first_dir_sect;
        $this->_hdr_first_mini_fat = $first_mini_fat;
        $this->_hdr_num_mini_fat   = $num_mini_fat;
        $this->_hdr_num_fat_sect   = $num_fat_sect;
        $this->_hdr_first_difat    = $first_difat;
        $this->_hdr_num_difat      = $num_difat;

        // First 109 DIFAT entries live in the header at 0x4C
        $this->_difat = array();
        for ($i = 0; $i < 109; $i++) {
            $v = $this->u32($hdr, 0x4C + $i * 4);
            if ($v === self::FREESECT) break;
            $this->_difat[] = $v;
        }

        // Follow additional DIFAT sectors if any
        $next = $first_difat;
        while ($next !== self::ENDOFCHAIN && $next !== self::FREESECT) {
            $sec = $this->readSector($next);
            for ($i = 0; $i < (int)(($this->sector_size - 4) / 4); $i++) {
                $v = $this->u32($sec, $i * 4);
                if ($v === self::FREESECT) break;
                $this->_difat[] = $v;
            }
            $next = $this->u32($sec, $this->sector_size - 4);
        }
    }

    // ─────────────────────────────────────────────────────────
    // FAT  (sector-chain table for the main stream)
    // ─────────────────────────────────────────────────────────
    private function parseFat() {
        $this->fat = array();
        foreach ($this->_difat as $fat_sect) {
            $sec = $this->readSector($fat_sect);
            $entries = (int)($this->sector_size / 4);
            for ($i = 0; $i < $entries; $i++) {
                $this->fat[] = $this->u32($sec, $i * 4);
            }
        }

        // Mini FAT
        $this->mini_fat = array();
        $next = $this->_hdr_first_mini_fat;
        while ($next !== self::ENDOFCHAIN && $next !== self::FREESECT) {
            $sec = $this->readSector($next);
            $entries = (int)($this->sector_size / 4);
            for ($i = 0; $i < $entries; $i++) {
                $this->mini_fat[] = $this->u32($sec, $i * 4);
            }
            $next = isset($this->fat[$next]) ? $this->fat[$next] : self::ENDOFCHAIN;
        }
    }

    // ─────────────────────────────────────────────────────────
    // DIRECTORY
    // ─────────────────────────────────────────────────────────
    private function parseDirectory() {
        $dir_bytes = $this->readFatStream($this->_hdr_first_dir_sect);

        // Each dir entry is 128 bytes
        $count = (int)(strlen($dir_bytes) / 128);
        for ($i = 0; $i < $count; $i++) {
            $e = substr($dir_bytes, $i * 128, 128);
            $name_len = $this->u16($e, 0x40);
            if ($name_len <= 0) continue;
            // name is UTF-16LE
            $name = '';
            for ($j = 0; $j < ($name_len - 2); $j += 2) {
                $c = ord($e[$j]) | (ord($e[$j + 1]) << 8);
                if ($c) $name .= chr($c & 0xFF);
            }
            $type       = ord($e[0x42]);
            $start_sect = $this->u32($e, 0x74);
            $size       = $this->u32($e, 0x78);

            $this->dir_entries[] = array(
                'name'   => $name,
                'type'   => $type,
                'start'  => $start_sect,
                'size'   => $size,
                'index'  => $i,
            );

            // Root entry (type 5) holds the mini stream
            if ($type === 5) {
                $this->root_mini_stream = $this->readFatStream($start_sect);
                // Trim to actual size
                if ($size > 0 && strlen($this->root_mini_stream) > $size) {
                    $this->root_mini_stream = substr($this->root_mini_stream, 0, $size);
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────
    // EXTRACT WORKBOOK STREAM
    // ─────────────────────────────────────────────────────────
    private function extractWorkbookStream() {
        foreach ($this->dir_entries as $entry) {
            if ($entry['type'] !== 2) continue; // stream entries only
            if ($entry['name'] === 'Workbook' || $entry['name'] === 'Book') {
                if ($entry['size'] < $this->mini_cutoff && $entry['size'] > 0) {
                    $this->workbook_stream = $this->readMiniStream($entry['start'], $entry['size']);
                } else {
                    $this->workbook_stream = $this->readFatStream($entry['start']);
                    if ($entry['size'] > 0 && strlen($this->workbook_stream) > $entry['size']) {
                        $this->workbook_stream = substr($this->workbook_stream, 0, $entry['size']);
                    }
                }
                return;
            }
        }
        throw new Exception('No Workbook/Book stream found in .xls');
    }

    // ─────────────────────────────────────────────────────────
    // PARSE BIFF RECORDS
    // ─────────────────────────────────────────────────────────
    private function parseBiffRecords() {
        $s = $this->workbook_stream;
        $len = strlen($s);
        $offset = 0;

        $sheet_offsets = array();    // [ [name, offset], ... ] from BOUNDSHEET
        $in_globals = true;
        $records = array();          // full record stream for later replay

        // Step 1: harvest BOUNDSHEET offsets and SST
        while ($offset + 4 <= $len) {
            $rec_type = $this->u16($s, $offset);
            $rec_len  = $this->u16($s, $offset + 2);
            $rec_data = substr($s, $offset + 4, $rec_len);
            $records[] = array('type' => $rec_type, 'data' => $rec_data, 'offset' => $offset);

            // BOUNDSHEET: 4 bytes offset + 1 byte hidden + 1 byte type + name
            if ($rec_type === self::BIFF_BOUNDSHEET) {
                $sheet_stream_pos = $this->u32($rec_data, 0);
                $sheet_offsets[]  = $sheet_stream_pos;
            }

            // SST: shared string table. Gather the SST record's payload
            // plus each immediately-following CONTINUE record as separate
            // segments so parseSst can track record boundaries (needed
            // because a single string's character data may be split
            // across records, and the first byte of the next CONTINUE
            // is a fresh option-flags byte for the interrupted string).
            if ($rec_type === self::BIFF_SST) {
                $segments = array($rec_data);
                $peek = $offset + 4 + $rec_len;
                while ($peek + 4 <= $len) {
                    $t = $this->u16($s, $peek);
                    $l = $this->u16($s, $peek + 2);
                    if ($t !== self::BIFF_CONTINUE) break;
                    $segments[] = substr($s, $peek + 4, $l);
                    $peek += 4 + $l;
                }
                $this->parseSst($segments);
            }

            $offset += 4 + $rec_len;
        }

        // Step 2: parse each sheet's substream
        foreach ($sheet_offsets as $sheet_idx => $sheet_start) {
            $cells = $this->parseSheetSubstream($sheet_start);
            $this->sheets['Sheet' . ($sheet_idx + 1)] = $cells;
        }
    }

    // ─────────────────────────────────────────────────────────
    // PARSE SHARED STRING TABLE
    // ─────────────────────────────────────────────────────────
    private function parseSst($segments) {
        if (empty($segments)) return;
        $first = $segments[0];
        if (strlen($first) < 8) return;

        // Unique-string count lives in bytes 4..7 of the SST header.
        $unique = $this->u32($first, 4);

        // Flatten segments into a single buffer, remembering the
        // absolute offsets (in the flattened buffer) at which each
        // CONTINUE record began. Those offsets are the only places
        // where a mid-string encoding-flag byte may appear.
        $data = substr($first, 8);
        $boundaries = array();
        $n_seg = count($segments);
        for ($i = 1; $i < $n_seg; $i++) {
            $boundaries[] = strlen($data);
            $data .= $segments[$i];
        }

        $this->sst_data         = $data;
        $this->sst_pos          = 0;
        $this->sst_boundaries   = $boundaries;
        $this->sst_boundary_set = array_flip($boundaries);
        $this->sst_bnd_ptr      = 0;

        for ($i = 0; $i < $unique; $i++) {
            $str = $this->sstReadString();
            if ($str === null) break;
            $this->sst[] = $str;
        }
    }

    private function sstReadString() {
        $dlen = strlen($this->sst_data);
        if ($this->sst_pos + 3 > $dlen) return null;

        $p = $this->sst_pos;
        $cch   = ord($this->sst_data[$p])   | (ord($this->sst_data[$p + 1]) << 8);
        $flags = ord($this->sst_data[$p + 2]);
        $this->sst_pos = $p + 3;

        $is_compressed = ($flags & 0x01) === 0;
        $has_rt        = ($flags & 0x08) !== 0;
        $has_phonetic  = ($flags & 0x04) !== 0;

        $rt_count = 0; $phon_size = 0;
        if ($has_rt) {
            if ($this->sst_pos + 2 > $dlen) return null;
            $rt_count = ord($this->sst_data[$this->sst_pos]) | (ord($this->sst_data[$this->sst_pos + 1]) << 8);
            $this->sst_pos += 2;
        }
        if ($has_phonetic) {
            if ($this->sst_pos + 4 > $dlen) return null;
            $phon_size = $this->u32($this->sst_data, $this->sst_pos);
            $this->sst_pos += 4;
        }

        // Advance the boundary pointer so it now points at the first
        // boundary at or after the current position. The header reads
        // above assume no flag-byte handling (spec says headers don't
        // span a CONTINUE boundary in practice).
        $nb = count($this->sst_boundaries);
        while ($this->sst_bnd_ptr < $nb && $this->sst_boundaries[$this->sst_bnd_ptr] < $this->sst_pos) {
            $this->sst_bnd_ptr++;
        }

        $str = $this->sstReadChars($cch, $is_compressed);

        if ($rt_count > 0)  $this->sst_pos += $rt_count * 4;
        if ($phon_size > 0) $this->sst_pos += $phon_size;

        // Re-sync boundary pointer after skipping rt/phonetic blobs.
        while ($this->sst_bnd_ptr < $nb && $this->sst_boundaries[$this->sst_bnd_ptr] < $this->sst_pos) {
            $this->sst_bnd_ptr++;
        }

        return $str;
    }

    /**
     * Read $cch characters of a string from the flattened SST buffer,
     * switching encoding at any CONTINUE boundary that falls inside
     * the character data.
     */
    private function sstReadChars($cch, $is_compressed) {
        $str = '';
        $remaining = $cch;
        $dlen = strlen($this->sst_data);
        $nb   = count($this->sst_boundaries);

        while ($remaining > 0 && $this->sst_pos < $dlen) {

            // If we are exactly at a boundary, consume the flag byte
            // that the CONTINUE record uses to re-announce the
            // compressed/uncompressed state for this interrupted string.
            if (isset($this->sst_boundary_set[$this->sst_pos])) {
                $f = ord($this->sst_data[$this->sst_pos]);
                $this->sst_pos++;
                $is_compressed = ($f & 0x01) === 0;
                while ($this->sst_bnd_ptr < $nb && $this->sst_boundaries[$this->sst_bnd_ptr] <= $this->sst_pos - 1) {
                    $this->sst_bnd_ptr++;
                }
            }

            // Bytes available before the next boundary.
            $next_b = ($this->sst_bnd_ptr < $nb) ? $this->sst_boundaries[$this->sst_bnd_ptr] : null;
            $bytes_avail = ($next_b !== null) ? ($next_b - $this->sst_pos) : ($dlen - $this->sst_pos);
            if ($bytes_avail <= 0) {
                // sst_pos must already equal next_b — loop will consume flag byte next iteration.
                if ($next_b === null) break;
                continue;
            }

            if ($is_compressed) {
                $take = $remaining < $bytes_avail ? $remaining : $bytes_avail;
                $chunk = substr($this->sst_data, $this->sst_pos, $take);
                for ($k = 0; $k < $take; $k++) {
                    $c = ord($chunk[$k]);
                    if ($c < 128) $str .= chr($c);
                    else          $str .= chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
                }
                $this->sst_pos += $take;
                $remaining     -= $take;
            } else {
                // Spec: a 2-byte wide character is never split across a CONTINUE.
                $max_chars = (int)($bytes_avail / 2);
                if ($max_chars <= 0) {
                    // Jump to the boundary so the flag byte gets consumed next iter.
                    if ($next_b === null) break;
                    $this->sst_pos = $next_b;
                    continue;
                }
                $take = $remaining < $max_chars ? $remaining : $max_chars;
                for ($k = 0; $k < $take; $k++) {
                    $c = ord($this->sst_data[$this->sst_pos]) | (ord($this->sst_data[$this->sst_pos + 1]) << 8);
                    if ($c < 128)       $str .= chr($c);
                    elseif ($c < 2048)  $str .= chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
                    else                $str .= chr(0xE0 | ($c >> 12)) . chr(0x80 | (($c >> 6) & 0x3F)) . chr(0x80 | ($c & 0x3F));
                    $this->sst_pos += 2;
                }
                $remaining -= $take;
            }
        }

        return $str;
    }

    // ─────────────────────────────────────────────────────────
    // PARSE A SHEET SUBSTREAM
    // ─────────────────────────────────────────────────────────
    private function parseSheetSubstream($start) {
        $s = $this->workbook_stream;
        $len = strlen($s);
        $offset = $start;
        $cells = array();   // [row][col] = value
        $pending_str_formula = null; // [row, col] waiting for STRING record

        while ($offset + 4 <= $len) {
            $rec_type = $this->u16($s, $offset);
            $rec_len  = $this->u16($s, $offset + 2);
            $rec_data = substr($s, $offset + 4, $rec_len);
            $offset += 4 + $rec_len;

            if ($rec_type === self::BIFF_EOF) break;

            // STRING record: fills the value of the previous string-result FORMULA
            if ($rec_type === self::BIFF_STRING && $pending_str_formula) {
                list($pr, $pc) = $pending_str_formula;
                if ($rec_len >= 3) {
                    $cch = $this->u16($rec_data, 0);
                    $flags = ord($rec_data[2]);
                    $compressed = ($flags & 0x01) === 0;
                    if ($compressed) {
                        $cells[$pr][$pc] = substr($rec_data, 3, $cch);
                    } else {
                        $bytes = $cch * 2;
                        $str = '';
                        for ($j = 0; $j < $bytes && (3 + $j + 1) < $rec_len; $j += 2) {
                            $c = ord($rec_data[3 + $j]) | (ord($rec_data[3 + $j + 1]) << 8);
                            if ($c < 128)       $str .= chr($c);
                            elseif ($c < 2048)  $str .= chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
                            else                $str .= chr(0xE0 | ($c >> 12)) . chr(0x80 | (($c >> 6) & 0x3F)) . chr(0x80 | ($c & 0x3F));
                        }
                        $cells[$pr][$pc] = $str;
                    }
                }
                $pending_str_formula = null;
                continue;
            }

            // Any record other than CONTINUE breaks the pending-STRING expectation
            if ($rec_type !== self::BIFF_CONTINUE && $pending_str_formula !== null) {
                $pending_str_formula = null;
            }

            switch ($rec_type) {

                case self::BIFF_LABELSST:
                    $row = $this->u16($rec_data, 0);
                    $col = $this->u16($rec_data, 2);
                    $idx = $this->u32($rec_data, 6);
                    $cells[$row][$col] = isset($this->sst[$idx]) ? $this->sst[$idx] : '';
                    break;

                case self::BIFF_LABEL:
                    $row = $this->u16($rec_data, 0);
                    $col = $this->u16($rec_data, 2);
                    $cch = $this->u16($rec_data, 6);
                    $cells[$row][$col] = substr($rec_data, 8, $cch);
                    break;

                case self::BIFF_NUMBER:
                    $row = $this->u16($rec_data, 0);
                    $col = $this->u16($rec_data, 2);
                    $val = $this->unpackFloat64(substr($rec_data, 6, 8));
                    $cells[$row][$col] = $this->fmtNum($val);
                    break;

                case self::BIFF_RK:
                    $row = $this->u16($rec_data, 0);
                    $col = $this->u16($rec_data, 2);
                    $rk  = $this->u32($rec_data, 6);
                    $cells[$row][$col] = $this->fmtNum($this->decodeRk($rk));
                    break;

                case self::BIFF_MULRK:
                    $row     = $this->u16($rec_data, 0);
                    $col_fst = $this->u16($rec_data, 2);
                    $col_lst = $this->u16($rec_data, $rec_len - 2);
                    $n = $col_lst - $col_fst + 1;
                    for ($i = 0; $i < $n; $i++) {
                        // Each RK record in MULRK is 6 bytes: 2 ixfe + 4 rk
                        $p = 4 + $i * 6;
                        $rk = $this->u32($rec_data, $p + 2);
                        $cells[$row][$col_fst + $i] = $this->fmtNum($this->decodeRk($rk));
                    }
                    break;

                case self::BIFF_FORMULA:
                    $row = $this->u16($rec_data, 0);
                    $col = $this->u16($rec_data, 2);
                    // Cached result occupies rec_data[6..13]. When rec_data[12]
                    // and rec_data[13] are both 0xFF, the result is a "special"
                    // type, and rec_data[6] tells us which:
                    //   0 = string   (next record will be a STRING with the value)
                    //   1 = boolean  (rec_data[8] holds 0/1)
                    //   2 = error    (rec_data[8] holds error code)
                    //   3 = empty
                    if (ord($rec_data[12]) === 0xFF && ord($rec_data[13]) === 0xFF) {
                        $result_type = ord($rec_data[6]);
                        if ($result_type === 0) {
                            // String result — next record is STRING, mark this cell pending
                            $pending_str_formula = array($row, $col);
                            $cells[$row][$col] = '';
                        } elseif ($result_type === 1) {
                            $cells[$row][$col] = ord($rec_data[8]) ? 'TRUE' : 'FALSE';
                        } elseif ($result_type === 2) {
                            $cells[$row][$col] = '#ERR';
                        } else {
                            $cells[$row][$col] = '';
                        }
                    } else {
                        $val = $this->unpackFloat64(substr($rec_data, 6, 8));
                        $cells[$row][$col] = $this->fmtNum($val);
                    }
                    break;

                case self::BIFF_BOOLERR:
                    $row = $this->u16($rec_data, 0);
                    $col = $this->u16($rec_data, 2);
                    $val = ord($rec_data[6]);
                    $is_err = ord($rec_data[7]);
                    $cells[$row][$col] = $is_err ? '#ERR' : ($val ? 'TRUE' : 'FALSE');
                    break;

                case self::BIFF_BLANK:
                case self::BIFF_MULBLANK:
                    // Nothing to record for blank cells
                    break;
            }
        }

        return $cells;
    }

    // ─────────────────────────────────────────────────────────
    // STREAM READING HELPERS
    // ─────────────────────────────────────────────────────────
    private function readSector($sector_num) {
        $offset = 512 + $sector_num * $this->sector_size;
        return substr($this->raw, $offset, $this->sector_size);
    }

    private function readFatStream($start_sector) {
        $out = '';
        $sec = $start_sector;
        $guard = 0;
        while ($sec !== self::ENDOFCHAIN && $sec !== self::FREESECT && $sec !== self::FATSECT && $sec < count($this->fat)) {
            $out .= $this->readSector($sec);
            $sec = $this->fat[$sec];
            if (++$guard > 100000) break; // infinite-loop guard
        }
        return $out;
    }

    private function readMiniStream($start_mini_sector, $size) {
        $out = '';
        $sec = $start_mini_sector;
        $guard = 0;
        while ($sec !== self::ENDOFCHAIN && $sec !== self::FREESECT && $sec < count($this->mini_fat)) {
            $offset = $sec * $this->mini_sector_size;
            $out .= substr($this->root_mini_stream, $offset, $this->mini_sector_size);
            $sec = $this->mini_fat[$sec];
            if (++$guard > 100000) break;
        }
        if ($size > 0 && strlen($out) > $size) $out = substr($out, 0, $size);
        return $out;
    }

    // ─────────────────────────────────────────────────────────
    // NUMBER FORMAT HELPERS
    // ─────────────────────────────────────────────────────────
    private function decodeRk($rk) {
        $is_int    = ($rk & 0x02) !== 0;
        $is_div100 = ($rk & 0x01) !== 0;
        if ($is_int) {
            // 30-bit signed integer
            $val = $rk >> 2;
            // Sign extend 30-bit
            if ($val & 0x20000000) $val |= ~0x3FFFFFFF;
        } else {
            // Top 30 bits are upper 30 bits of a double (mantissa high)
            $bits = $rk & ~0x03;
            $packed = pack('V2', 0, $bits);
            $val = $this->unpackFloat64($packed);
        }
        if ($is_div100) $val = $val / 100.0;
        return $val;
    }

    private function unpackFloat64($bytes) {
        if (strlen($bytes) < 8) return 0.0;
        $arr = unpack('d', $bytes);
        return $arr[1];
    }

    private function fmtNum($n) {
        // Format numbers for string output — integers as "123", decimals as-is.
        if (is_finite($n)) {
            if ((float)(int)$n === (float)$n && abs($n) < 1e15) return (string)(int)$n;
            return rtrim(rtrim(sprintf('%.6f', $n), '0'), '.');
        }
        return '';
    }

    // ─────────────────────────────────────────────────────────
    // BINARY READ HELPERS (little-endian)
    // ─────────────────────────────────────────────────────────
    private function u16($s, $o) {
        if ($o + 2 > strlen($s)) return 0;
        return ord($s[$o]) | (ord($s[$o + 1]) << 8);
    }

    private function u32($s, $o) {
        if ($o + 4 > strlen($s)) return 0;
        $v = ord($s[$o]) | (ord($s[$o + 1]) << 8) | (ord($s[$o + 2]) << 16) | (ord($s[$o + 3]) << 24);
        return $v < 0 ? $v + 0x100000000 : $v;
    }
}
