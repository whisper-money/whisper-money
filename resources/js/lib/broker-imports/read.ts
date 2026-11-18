import { type BrokerGrid } from '@/lib/broker-imports/types';
import { parseFile } from '@/lib/file-parser';

/** The rows of a file as `parseFile` read them, turned back from its columns. */
export function gridFromColumns(columns: unknown[][]): BrokerGrid {
    const height = Math.max(0, ...columns.map((column) => column.length));

    return Array.from({ length: height }, (_, rowIndex) =>
        columns.map((column) => column[rowIndex]),
    );
}

/**
 * Reads a file into its grid, with the same reader the import drawer uses,
 * for `detectBrokerFile` and the broker parsers.
 */
export async function readBrokerGrid(file: File): Promise<BrokerGrid> {
    const { columns } = await parseFile(file);

    return gridFromColumns(columns);
}
