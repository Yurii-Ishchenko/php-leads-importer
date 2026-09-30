#!/usr/bin/env python3
"""Read MySQL directly and compare counts, NULLs, duplicates and every row's hash."""
import argparse
import hashlib
import json
import subprocess

COLUMNS = ['external_id', 'created_at', 'first_name', 'last_name', 'phone', 'email',
           'city', 'source', 'utm_campaign', 'product', 'budget_uah', 'status',
           'manager', 'comment', 'next_contact_at']


def query(sql):
    result = subprocess.run([
        'docker', 'compose', 'exec', '-T', 'mysql', 'sh', '-c',
        'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql --default-character-set=utf8mb4 '
        '--user="$MYSQL_USER" --database="$MYSQL_DATABASE" --batch --raw --skip-column-names',
    ], input=sql, text=True, capture_output=True, check=True)
    return result.stdout.strip().splitlines()


def verify(import_id, source):
    where = f'WHERE import_id = {int(import_id)}'
    report = json.loads(query(
        "SELECT JSON_OBJECT('status', status, 'processed_rows', processed_rows, "
        "'total_rows', total_rows, 'duration_ms', duration_ms, 'error', error, "
        f"'stored_path', path) FROM imports WHERE id={int(import_id)};"
    )[0])
    counts = query(f'SELECT COUNT(*),COUNT(DISTINCT external_id) FROM leads {where};')[0].split('\t')
    report['mysql_rows'] = int(counts[0])
    report['unique_external_ids'] = int(counts[1])
    report['all_leads_rows'] = int(query('SELECT COUNT(*) FROM leads;')[0])
    null_query = ','.join(f'SUM(`{name}` IS NULL)' for name in COLUMNS)
    report['nulls'] = dict(zip(COLUMNS, map(int, query(f'SELECT {null_query} FROM leads {where};')[0].split('\t'))))
    histogram = query(f'SELECT n,COUNT(*) FROM (SELECT COUNT(*) n FROM leads {where} GROUP BY external_id HAVING COUNT(*)>1) d GROUP BY n ORDER BY n;')
    report['duplicate_multiplicity_histogram'] = {n: int(count) for n, count in (line.split('\t') for line in histogram)}
    report['duplicate_external_id_groups'] = sum(report['duplicate_multiplicity_histogram'].values())
    report['rows_in_duplicate_groups'] = sum(int(n) * count for n, count in report['duplicate_multiplicity_histogram'].items())
    fields = ','.join(f"COALESCE(CAST(`{name}` AS CHAR), '{{NULL}}')" for name in COLUMNS)
    row_hashes = query(f'SELECT SHA2(CONCAT_WS(CHAR(31),{fields}),256) FROM leads {where} ORDER BY source_row;')
    report['ordered_rows_sha256'] = hashlib.sha256(('\n'.join(row_hashes) + '\n').encode()).hexdigest()
    id_hashes = query(f"SELECT CONCAT(SHA2(external_id,256),':',COUNT(*)) FROM leads {where} GROUP BY external_id ORDER BY external_id COLLATE utf8mb4_bin;")
    report['external_id_multiplicities_sha256'] = hashlib.sha256(('\n'.join(id_hashes) + '\n').encode()).hexdigest()
    report['failed_jobs_all'] = int(query('SELECT COUNT(*) FROM failed_jobs;')[0])
    report['pending_jobs_all'] = int(query('SELECT COUNT(*) FROM jobs;')[0])
    report['checks'] = {
        'completed': report['status'] == 'completed',
        'processed_rows_equal_source': report['processed_rows'] == source['data_rows'],
        'mysql_rows_equal_source': report['mysql_rows'] == source['data_rows'],
        'all_leads_rows_equal_source': report['all_leads_rows'] == source['data_rows'],
        'duplicate_multiplicities_match': report['external_id_multiplicities_sha256'] == source['external_id_multiplicities_sha256'],
        'null_counts_match': report['nulls'] == source['nulls'],
        'all_row_values_match': report['ordered_rows_sha256'] == source['ordered_rows_sha256'],
        'no_failed_jobs': report['failed_jobs_all'] == 0,
        'no_pending_jobs': report['pending_jobs_all'] == 0,
        'no_import_error': report['error'] is None,
    }
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--import-id', type=int, required=True)
    parser.add_argument('--source-audit', required=True)
    args = parser.parse_args()
    with open(args.source_audit) as stream:
        source = json.load(stream)
    report = verify(args.import_id, source)
    print(json.dumps(report, ensure_ascii=False, indent=2))
    raise SystemExit(0 if all(report['checks'].values()) else 1)
