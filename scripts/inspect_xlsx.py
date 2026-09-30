#!/usr/bin/env python3
"""Independent XLSX audit; outputs only aggregates and hashes, never source rows."""
import argparse
from collections import Counter
from datetime import datetime, timedelta
from decimal import Decimal, ROUND_HALF_UP
import hashlib
import json
import xml.etree.ElementTree as ET
import zipfile

NS = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}


def elements(archive, name, tag):
    with archive.open(name) as stream:
        context = ET.iterparse(stream, events=('start', 'end'))
        _, root = next(context)
        for event, element in context:
            if event == 'end' and element.tag == '{' + NS['m'] + '}' + tag:
                yield element
                element.clear()
                root.clear()


def inspect(path, row_callback=None):
    strings = []
    ids = Counter()
    nulls = Counter()
    digest = hashlib.sha256()
    count = 0
    with zipfile.ZipFile(path) as archive:
        if 'xl/sharedStrings.xml' in archive.namelist():
            for element in elements(archive, 'xl/sharedStrings.xml', 'si'):
                strings.append(''.join(t.text or '' for t in element.findall('.//m:t', NS)))
        workbook = ET.fromstring(archive.read('xl/workbook.xml'))
        date1904 = workbook.find('m:workbookPr', NS).get('date1904', '0') == '1'
        relid = workbook.find('m:sheets/m:sheet', NS).get('{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id')
        relations = ET.fromstring(archive.read('xl/_rels/workbook.xml.rels'))
        target = next(r.get('Target') for r in relations if r.get('Id') == relid)
        sheet = target.lstrip('/') if target.startswith('/') else 'xl/' + target
        header = None
        for row in elements(archive, sheet, 'row'):
            cells = {}
            for cell in row.findall('m:c', NS):
                letters = ''.join(c for c in cell.get('r') if c.isalpha())
                index = 0
                for letter in letters:
                    index = index * 26 + ord(letter) - ord('A') + 1
                value = cell.find('m:v', NS)
                value = value.text if value is not None else None
                if cell.get('t') == 's' and value is not None:
                    value = strings[int(value)]
                elif cell.get('t') == 'inlineStr':
                    value = ''.join(t.text or '' for t in cell.findall('.//m:t', NS))
                elif cell.get('t', 'n') == 'n' and value not in (None, ''):
                    # XLSX numeric 12345.0 and 12345 represent the same value.
                    # Text cells keep their original characters, including zeroes.
                    number = Decimal(value)
                    if number == number.to_integral_value():
                        value = format(number.to_integral_value(), 'f')
                cells[index - 1] = value
            if header is None:
                header = [cells.get(i) for i in range(max(cells) + 1)]
                continue
            if not any(v not in (None, '') for v in cells.values()):
                continue
            values = []
            for i, name in enumerate(header):
                value = cells.get(i)
                if value in (None, ''):
                    value = None
                    nulls[name] += 1
                elif name in ('created_at', 'next_contact_at'):
                    try:
                        seconds = int((Decimal(value) * 86400).quantize(Decimal('1'), rounding=ROUND_HALF_UP))
                        origin = datetime(1904, 1, 1) if date1904 else datetime(1899, 12, 30)
                        value = (origin + timedelta(seconds=seconds)).strftime('%Y-%m-%d %H:%M:%S')
                    except Exception:
                        value = datetime.fromisoformat(value).strftime('%Y-%m-%d %H:%M:%S')
                elif name == 'budget_uah':
                    value = str(Decimal(value).quantize(Decimal('0.01'), rounding=ROUND_HALF_UP))
                values.append(value)
            ids[values[0]] += 1
            if row_callback is not None:
                row_callback(int(row.get('r')), values)
            # Matches MySQL SHA2(CONCAT_WS(CHAR(31), COALESCE(column, '{NULL}'), ...), 256).
            encoded = '\x1f'.join(v if v is not None else '{NULL}' for v in values).encode()
            digest.update(hashlib.sha256(encoded).hexdigest().encode() + b'\n')
            count += 1
    duplicate_counts = Counter(n for n in ids.values() if n > 1)
    id_digest = hashlib.sha256()
    for value, n in sorted(ids.items()):
        id_digest.update(hashlib.sha256(value.encode()).hexdigest().encode() + b':' + str(n).encode() + b'\n')
    return {
        'data_rows': count,
        'unique_external_ids': len(ids),
        'duplicate_external_id_groups': sum(duplicate_counts.values()),
        'rows_in_duplicate_groups': sum(n * groups for n, groups in duplicate_counts.items()),
        'duplicate_multiplicity_histogram': dict(sorted(duplicate_counts.items())),
        'nulls': {name: nulls[name] for name in header},
        'ordered_rows_sha256': digest.hexdigest(),
        'external_id_multiplicities_sha256': id_digest.hexdigest(),
    }


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('xlsx', help='Path to a user-supplied XLSX for verification only')
    arguments = parser.parse_args()
    print(json.dumps(inspect(arguments.xlsx), ensure_ascii=False, indent=2))
