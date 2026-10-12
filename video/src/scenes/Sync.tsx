import { CheckIcon, LoaderIcon, SparklesIcon } from 'lucide-react';
import { useCurrentFrame } from 'remotion';
import { enter, pop } from '../animation';
import { Amount } from '../components/Amount';
import { AppWindow } from '../components/AppWindow';
import { CategoryBadge } from '../components/CategoryBadge';
import { SceneLayout } from '../components/SceneLayout';
import { useVideo } from '../context';

const ACCOUNTS = [
    { name: 'Payroll', initials: 'PB', color: '#1d4ed8', connectedAt: 36 },
    { name: 'Daily', initials: 'DB', color: '#ea580c', connectedAt: 48 },
    { name: 'Savings', initials: 'SV', color: '#0f766e', connectedAt: 60 },
];
const FIRST_ROW_AT = 44;
const ROW_STAGGER = 9;
const CATEGORIZE_DELAY = 22;

export function Sync() {
    const frame = useCurrentFrame();
    const { theme, copy } = useVideo();
    const autoIn = pop(frame, FIRST_ROW_AT + CATEGORIZE_DELAY + 8);

    return (
        <SceneLayout title={copy.sync.title} subtitle={copy.sync.subtitle}>
            <AppWindow active="transactions" title={copy.sync.pageTitle} width={1560} height={690}>
                <div style={{ display: 'flex', gap: 28, height: '100%' }}>
                    <div style={{ width: 360, display: 'flex', flexDirection: 'column', gap: 16 }}>
                        {ACCOUNTS.map((account, index) => {
                            const cardIn = enter(frame, 14 + index * 6);
                            const isConnected = frame >= account.connectedAt;
                            const checkIn = pop(frame, account.connectedAt);

                            return (
                                <div
                                    key={account.name}
                                    style={{
                                        opacity: cardIn,
                                        transform: `translateX(${(1 - cardIn) * -40}px)`,
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 16,
                                        padding: '20px 22px',
                                        borderRadius: 16,
                                        border: `1.5px solid ${theme.border}`,
                                        background: theme.background,
                                    }}
                                >
                                    <div
                                        style={{
                                            width: 48,
                                            height: 48,
                                            borderRadius: 999,
                                            background: account.color,
                                            color: '#fff',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            fontSize: 17,
                                            fontWeight: 700,
                                        }}
                                    >
                                        {account.initials}
                                    </div>
                                    <div style={{ display: 'flex', flexDirection: 'column', flex: 1 }}>
                                        <span style={{ fontSize: 24, fontWeight: 600 }}>{account.name}</span>
                                        <span style={{ fontSize: 18, color: isConnected ? theme.positive : theme.muted }}>
                                            {isConnected ? copy.sync.connected : copy.sync.syncing}
                                        </span>
                                    </div>
                                    {isConnected ? (
                                        <div
                                            style={{
                                                transform: `scale(${checkIn})`,
                                                width: 34,
                                                height: 34,
                                                borderRadius: 999,
                                                background: theme.positiveSoft,
                                                color: theme.positive,
                                                display: 'flex',
                                                alignItems: 'center',
                                                justifyContent: 'center',
                                            }}
                                        >
                                            <CheckIcon size={20} strokeWidth={3} />
                                        </div>
                                    ) : (
                                        <LoaderIcon size={26} color={theme.muted} style={{ transform: `rotate(${frame * 12}deg)` }} />
                                    )}
                                </div>
                            );
                        })}
                        <div
                            style={{
                                marginTop: 'auto',
                                opacity: autoIn,
                                transform: `scale(${0.8 + autoIn * 0.2})`,
                                display: 'flex',
                                alignItems: 'center',
                                gap: 12,
                                padding: '16px 20px',
                                borderRadius: 16,
                                background: theme.surfaceMuted,
                                fontSize: 21,
                                fontWeight: 500,
                                color: theme.muted,
                            }}
                        >
                            <SparklesIcon size={24} color={theme.warning} />
                            {copy.sync.autoCategorized}
                        </div>
                    </div>
                    <div
                        style={{
                            flex: 1,
                            borderRadius: 16,
                            border: `1.5px solid ${theme.border}`,
                            overflow: 'hidden',
                        }}
                    >
                        <div
                            style={{
                                display: 'flex',
                                padding: '18px 28px',
                                fontSize: 19,
                                fontWeight: 500,
                                color: theme.muted,
                                borderBottom: `1.5px solid ${theme.border}`,
                            }}
                        >
                            <span style={{ width: 340 }}>{copy.sync.columns[0]}</span>
                            <span style={{ flex: 1 }}>{copy.sync.columns[1]}</span>
                            <span>{copy.sync.columns[2]}</span>
                        </div>
                        {copy.sync.transactions.map((transaction, index) => {
                            const rowAt = FIRST_ROW_AT + index * ROW_STAGGER;
                            const rowIn = enter(frame, rowAt, 18);
                            const badgeIn = pop(frame, rowAt + CATEGORIZE_DELAY);
                            const isCategorized = frame >= rowAt + CATEGORIZE_DELAY;

                            return (
                                <div
                                    key={transaction.description}
                                    style={{
                                        opacity: rowIn,
                                        transform: `translateY(${(1 - rowIn) * -24}px)`,
                                        display: 'flex',
                                        alignItems: 'center',
                                        height: 78,
                                        padding: '0 28px',
                                        borderBottom: `1.5px solid ${theme.border}`,
                                    }}
                                >
                                    <div style={{ width: 340 }}>
                                        {isCategorized ? (
                                            <div style={{ transform: `scale(${badgeIn})`, transformOrigin: 'left center' }}>
                                                <CategoryBadge index={index} name={transaction.category} />
                                            </div>
                                        ) : (
                                            <div
                                                style={{
                                                    width: 200,
                                                    height: 22,
                                                    borderRadius: 8,
                                                    background: theme.surfaceMuted,
                                                    opacity: 0.6 + Math.sin(frame / 3) * 0.4,
                                                }}
                                            />
                                        )}
                                    </div>
                                    <span style={{ flex: 1, fontSize: 22, color: theme.muted }}>{transaction.description}</span>
                                    <Amount value={transaction.amount} />
                                </div>
                            );
                        })}
                    </div>
                </div>
            </AppWindow>
        </SceneLayout>
    );
}
