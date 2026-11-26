import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { horizonEnd } from './calendar';
import { expandOccurrences } from './expand-occurrences';
import { projectForecast } from './project';
import type {
    ExpandableForecastItem,
    ForecastOccurrence,
    MonthlyEstimate,
    Projection,
    ProjectionAccount,
} from './types';

interface ParityFixture {
    name: string;
    input: {
        items: ExpandableForecastItem[];
        accounts: ProjectionAccount[];
        estimates: MonthlyEstimate[];
        startDate: string;
        horizonMonths: number;
    };
    expected: {
        occurrences: ForecastOccurrence[];
        projection: Projection;
    };
}

// The same fixtures drive the PHP ForecastParityTest, so both engines must
// agree on every occurrence and projected figure here: the client recalculates
// while the user edits, then takes the server's answer.
const fixtures = JSON.parse(
    readFileSync(
        resolve(process.cwd(), 'tests/Fixtures/forecast-parity.json'),
        'utf-8',
    ),
) as ParityFixture[];

describe('forecast engine PHP/TS parity', () => {
    it.each(fixtures)('$name', ({ input, expected }) => {
        const occurrences = expandOccurrences(
            input.items,
            input.startDate,
            horizonEnd(input.startDate, input.horizonMonths),
        );

        expect(occurrences).toEqual(expected.occurrences);
        expect(projectForecast({ ...input, occurrences })).toEqual(
            expected.projection,
        );
    });
});
