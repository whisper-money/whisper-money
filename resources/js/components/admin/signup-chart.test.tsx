import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { get: mocks.get },
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

const { SignupChart } = await import('./signup-chart');
type SignupChartData = import('./signup-chart').SignupChartData;

function chart(overrides: Partial<SignupChartData> = {}): SignupChartData {
    return {
        period_type: 'month',
        period: '2026-03',
        previous_period: '2026-02',
        bucket: 'day',
        points: [
            {
                start: '2026-03-01',
                end: '2026-03-01',
                signups: 6,
                active_users: 40,
                previous: {
                    start: '2026-02-01',
                    end: '2026-02-01',
                    signups: 4,
                    active_users: 50,
                },
            },
        ],
        summary: {
            signups: 6,
            active_users: 40,
            previous_signups: 4,
            previous_active_users: 50,
            in_progress: false,
        },
        ...overrides,
    };
}

describe('SignupChart', () => {
    beforeEach(() => {
        mocks.get.mockClear();
    });

    it('shows the period totals, and their change only with the comparison on', () => {
        render(<SignupChart chart={chart()} />);

        expect(screen.getByText('6')).toBeTruthy();
        expect(screen.getByText('40')).toBeTruthy();
        expect(screen.queryByText('+50.0%')).toBeNull();

        fireEvent.click(screen.getByRole('switch'));

        expect(screen.getByText('+50.0%')).toBeTruthy();
        expect(screen.getByText('-20.0%')).toBeTruthy();
        expect(screen.getAllByText('vs last period')).toHaveLength(2);
    });

    it('says so when the previous period is cut at the same point', () => {
        const data = chart();
        render(
            <SignupChart
                chart={{
                    ...data,
                    summary: { ...data.summary, in_progress: true },
                }}
            />,
        );

        fireEvent.click(screen.getByRole('switch'));

        expect(screen.getAllByText('vs same point last period')).toHaveLength(
            2,
        );
    });

    it('asks the server for the chart alone when the period moves', () => {
        render(<SignupChart chart={chart()} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Previous period' }),
        );

        expect(mocks.get).toHaveBeenCalledOnce();
        const [url, , options] = mocks.get.mock.calls[0];
        expect(url).toBe('/admin?period_type=month&period=2026-02');
        expect(options).toMatchObject({
            only: ['signupChart'],
            preserveScroll: true,
            preserveState: true,
        });
    });

    it('keeps the date when switching to a year', () => {
        render(<SignupChart chart={chart()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Year' }));

        expect(mocks.get.mock.calls[0][0]).toBe(
            '/admin?period_type=year&period=2026',
        );
    });
});
