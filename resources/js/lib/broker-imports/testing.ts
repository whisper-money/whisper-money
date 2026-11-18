import { readBrokerGrid } from '@/lib/broker-imports/grid';
import { type BrokerGrid } from '@/lib/broker-imports/types';
import { readFileSync } from 'node:fs';

/** A file from `resources/js/lib/__fixtures__`, as the browser would hand it over. */
export function fixtureFile(name: string): File {
    return new File(
        [new Uint8Array(readFileSync(`resources/js/lib/__fixtures__/${name}`))],
        name,
    );
}

/** A fixture read into its grid. Every fixture is synthetic: no real person in it. */
export function fixtureGrid(name: string): Promise<BrokerGrid> {
    return readBrokerGrid(fixtureFile(name));
}
