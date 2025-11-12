#!/usr/bin/env python3
"""
Daily Telemetry Export Job

Aggregates telemetry data into daily summaries for fast dashboard queries.
Exports telemetry to JSON files for archival and analysis.

Usage:
    python export_telemetry.py [--date YYYY-MM-DD] [--export-path /path/to/exports]

Runs daily via cron:
    0 2 * * * /usr/bin/python3 /path/to/export_telemetry.py

Author: Sprint 4 - API Rate Limiting & Telemetry
Date: 2025-11-12
"""

import sys
import os
import json
import argparse
from datetime import datetime, timedelta
from typing import Dict, List, Optional
import mysql.connector
from mysql.connector import Error
import gzip

# Configuration (load from environment or config file)
DB_CONFIG = {
    'host': os.getenv('DB_HOST', 'localhost'),
    'port': int(os.getenv('DB_PORT', '3306')),
    'database': os.getenv('DB_NAME', 'orchestrator'),
    'user': os.getenv('DB_USER', 'orchestrator_user'),
    'password': os.getenv('DB_PASSWORD', ''),
}

EXPORT_BASE_PATH = os.getenv('TELEMETRY_EXPORT_PATH', '/var/log/orchestrator/telemetry_exports')


class TelemetryExporter:
    """Handles telemetry aggregation and export"""

    def __init__(self, db_config: Dict):
        self.db_config = db_config
        self.connection = None

    def __enter__(self):
        """Context manager entry"""
        self.connect()
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        """Context manager exit"""
        self.close()

    def connect(self):
        """Establish database connection"""
        try:
            self.connection = mysql.connector.connect(**self.db_config)
            print(f"✓ Connected to database: {self.db_config['database']}")
        except Error as e:
            print(f"✗ Database connection failed: {e}")
            raise

    def close(self):
        """Close database connection"""
        if self.connection and self.connection.is_connected():
            self.connection.close()
            print("✓ Database connection closed")

    def aggregate_daily_summaries(self, date: str) -> int:
        """
        Aggregate telemetry data into daily summary table

        Args:
            date: Date to aggregate (YYYY-MM-DD)

        Returns:
            Number of summary rows created
        """
        print(f"\n📊 Aggregating daily summaries for {date}...")

        cursor = self.connection.cursor(dictionary=True)

        try:
            # Calculate percentiles and aggregates per tenant, endpoint, and api_key
            query = """
                INSERT INTO telemetry_daily_summary
                    (tenant_id, api_key_id, endpoint, date,
                     total_requests, successful_requests, failed_requests,
                     avg_duration_ms, p50_duration_ms, p95_duration_ms, p99_duration_ms, max_duration_ms)
                SELECT
                    tenant_id,
                    api_key_id,
                    endpoint,
                    DATE(created_at) as date,
                    COUNT(*) as total_requests,
                    SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests,
                    AVG(duration_ms) as avg_duration_ms,
                    -- Approximate percentiles using subqueries
                    (SELECT duration_ms FROM api_telemetry t2
                     WHERE t2.tenant_id = t1.tenant_id
                       AND t2.endpoint = t1.endpoint
                       AND DATE(t2.created_at) = DATE(t1.created_at)
                       AND (t2.api_key_id = t1.api_key_id OR (t2.api_key_id IS NULL AND t1.api_key_id IS NULL))
                     ORDER BY duration_ms
                     LIMIT 1 OFFSET GREATEST(0, FLOOR(COUNT(*) * 0.5) - 1)
                    ) as p50_duration_ms,
                    (SELECT duration_ms FROM api_telemetry t2
                     WHERE t2.tenant_id = t1.tenant_id
                       AND t2.endpoint = t1.endpoint
                       AND DATE(t2.created_at) = DATE(t1.created_at)
                       AND (t2.api_key_id = t1.api_key_id OR (t2.api_key_id IS NULL AND t1.api_key_id IS NULL))
                     ORDER BY duration_ms
                     LIMIT 1 OFFSET GREATEST(0, FLOOR(COUNT(*) * 0.95) - 1)
                    ) as p95_duration_ms,
                    (SELECT duration_ms FROM api_telemetry t2
                     WHERE t2.tenant_id = t1.tenant_id
                       AND t2.endpoint = t1.endpoint
                       AND DATE(t2.created_at) = DATE(t1.created_at)
                       AND (t2.api_key_id = t1.api_key_id OR (t2.api_key_id IS NULL AND t1.api_key_id IS NULL))
                     ORDER BY duration_ms
                     LIMIT 1 OFFSET GREATEST(0, FLOOR(COUNT(*) * 0.99) - 1)
                    ) as p99_duration_ms,
                    MAX(duration_ms) as max_duration_ms
                FROM api_telemetry t1
                WHERE DATE(created_at) = %s
                GROUP BY tenant_id, api_key_id, endpoint, DATE(created_at)
                ON DUPLICATE KEY UPDATE
                    total_requests = VALUES(total_requests),
                    successful_requests = VALUES(successful_requests),
                    failed_requests = VALUES(failed_requests),
                    avg_duration_ms = VALUES(avg_duration_ms),
                    p50_duration_ms = VALUES(p50_duration_ms),
                    p95_duration_ms = VALUES(p95_duration_ms),
                    p99_duration_ms = VALUES(p99_duration_ms),
                    max_duration_ms = VALUES(max_duration_ms),
                    updated_at = CURRENT_TIMESTAMP
            """

            cursor.execute(query, (date,))
            self.connection.commit()

            rows_affected = cursor.rowcount
            print(f"✓ Created/updated {rows_affected} summary rows for {date}")

            return rows_affected

        except Error as e:
            print(f"✗ Failed to aggregate summaries: {e}")
            self.connection.rollback()
            raise
        finally:
            cursor.close()

    def export_to_json(self, date: str, export_path: str) -> str:
        """
        Export telemetry data to compressed JSON file

        Args:
            date: Date to export (YYYY-MM-DD)
            export_path: Base path for exports

        Returns:
            Path to exported file
        """
        print(f"\n📦 Exporting telemetry to JSON for {date}...")

        cursor = self.connection.cursor(dictionary=True)

        try:
            # Create export directory if it doesn't exist
            os.makedirs(export_path, exist_ok=True)

            # Fetch all telemetry for the date
            query = """
                SELECT
                    request_id,
                    tenant_id,
                    api_key_id,
                    user_id,
                    method,
                    endpoint,
                    status_code,
                    duration_ms,
                    db_queries,
                    db_time_ms,
                    error_message,
                    error_code,
                    ip_address,
                    created_at
                FROM api_telemetry
                WHERE DATE(created_at) = %s
                ORDER BY created_at ASC
            """

            cursor.execute(query, (date,))
            records = cursor.fetchall()

            # Convert datetime objects to strings
            for record in records:
                if 'created_at' in record and record['created_at']:
                    record['created_at'] = record['created_at'].isoformat()

            # Prepare export data
            export_data = {
                'export_date': datetime.now().isoformat(),
                'data_date': date,
                'record_count': len(records),
                'records': records
            }

            # Write to compressed JSON file
            filename = f"telemetry_{date}.json.gz"
            filepath = os.path.join(export_path, filename)

            with gzip.open(filepath, 'wt', encoding='utf-8') as f:
                json.dump(export_data, f, indent=2, ensure_ascii=False)

            file_size = os.path.getsize(filepath)
            print(f"✓ Exported {len(records)} records to {filepath}")
            print(f"  File size: {file_size / 1024:.2f} KB")

            return filepath

        except Error as e:
            print(f"✗ Failed to export telemetry: {e}")
            raise
        finally:
            cursor.close()

    def cleanup_old_telemetry(self, retention_days: int = 90) -> int:
        """
        Delete telemetry records older than retention period

        Args:
            retention_days: Number of days to retain

        Returns:
            Number of records deleted
        """
        print(f"\n🗑️  Cleaning up telemetry older than {retention_days} days...")

        cursor = self.connection.cursor()

        try:
            cutoff_date = (datetime.now() - timedelta(days=retention_days)).strftime('%Y-%m-%d')

            query = """
                DELETE FROM api_telemetry
                WHERE DATE(created_at) < %s
            """

            cursor.execute(query, (cutoff_date,))
            self.connection.commit()

            rows_deleted = cursor.rowcount
            print(f"✓ Deleted {rows_deleted} old telemetry records (before {cutoff_date})")

            return rows_deleted

        except Error as e:
            print(f"✗ Failed to cleanup telemetry: {e}")
            self.connection.rollback()
            raise
        finally:
            cursor.close()

    def get_summary_stats(self, date: str) -> Optional[Dict]:
        """
        Get summary statistics for a date

        Args:
            date: Date to query (YYYY-MM-DD)

        Returns:
            Summary statistics dictionary
        """
        cursor = self.connection.cursor(dictionary=True)

        try:
            query = """
                SELECT
                    COUNT(*) as total_requests,
                    COUNT(DISTINCT tenant_id) as unique_tenants,
                    COUNT(DISTINCT api_key_id) as unique_api_keys,
                    COUNT(DISTINCT endpoint) as unique_endpoints,
                    SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests,
                    AVG(duration_ms) as avg_duration_ms,
                    MAX(duration_ms) as max_duration_ms,
                    AVG(db_queries) as avg_db_queries
                FROM api_telemetry
                WHERE DATE(created_at) = %s
            """

            cursor.execute(query, (date,))
            result = cursor.fetchone()

            return result

        except Error as e:
            print(f"✗ Failed to get summary stats: {e}")
            return None
        finally:
            cursor.close()


def main():
    """Main execution function"""
    parser = argparse.ArgumentParser(description='Export and aggregate daily telemetry')
    parser.add_argument('--date', type=str, help='Date to process (YYYY-MM-DD). Default: yesterday')
    parser.add_argument('--export-path', type=str, default=EXPORT_BASE_PATH, help='Export directory path')
    parser.add_argument('--retention-days', type=int, default=90, help='Telemetry retention period')
    parser.add_argument('--skip-export', action='store_true', help='Skip JSON export')
    parser.add_argument('--skip-cleanup', action='store_true', help='Skip old data cleanup')

    args = parser.parse_args()

    # Default to yesterday if no date specified
    if args.date:
        target_date = args.date
    else:
        target_date = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')

    print("=" * 60)
    print(f"📊 Telemetry Export Job - {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Target date: {target_date}")
    print("=" * 60)

    try:
        with TelemetryExporter(DB_CONFIG) as exporter:
            # 1. Aggregate daily summaries
            exporter.aggregate_daily_summaries(target_date)

            # 2. Get summary stats
            stats = exporter.get_summary_stats(target_date)
            if stats:
                print(f"\n📈 Summary for {target_date}:")
                print(f"   Total requests: {stats['total_requests']}")
                print(f"   Unique tenants: {stats['unique_tenants']}")
                print(f"   Unique API keys: {stats['unique_api_keys']}")
                print(f"   Unique endpoints: {stats['unique_endpoints']}")
                print(f"   Success rate: {stats['successful_requests'] / max(stats['total_requests'], 1) * 100:.2f}%")
                print(f"   Avg duration: {stats['avg_duration_ms']:.2f}ms")
                print(f"   Max duration: {stats['max_duration_ms']:.2f}ms")

            # 3. Export to JSON
            if not args.skip_export:
                export_file = exporter.export_to_json(target_date, args.export_path)
                print(f"\n✓ Export complete: {export_file}")

            # 4. Cleanup old telemetry
            if not args.skip_cleanup:
                deleted = exporter.cleanup_old_telemetry(args.retention_days)
                print(f"\n✓ Cleanup complete: {deleted} records deleted")

        print("\n" + "=" * 60)
        print("✅ Telemetry export job completed successfully")
        print("=" * 60)
        return 0

    except Exception as e:
        print("\n" + "=" * 60)
        print(f"❌ Telemetry export job failed: {e}")
        print("=" * 60)
        return 1


if __name__ == '__main__':
    sys.exit(main())
