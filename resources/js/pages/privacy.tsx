import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Privacy() {
    const { appUrl } = usePage<SharedData>().props;

    return (
        <>
            <Head title={__('Privacy Policy - Whisper Money')}>
                <meta
                    name="description"
                    content={__(
                        'Privacy policy for Whisper Money. Learn how we collect, use, and protect your personal information.',
                    )}
                />

                <link rel="canonical" href={`${appUrl}/privacy`} />
                <meta name="robots" content={__('index, follow')} />

                <meta
                    property="og:title"
                    content={__('Privacy Policy - Whisper Money')}
                />

                <meta
                    property="og:description"
                    content={__(
                        'Privacy policy for Whisper Money. Learn how we collect, use, and protect your personal information.',
                    )}
                />

                <meta property="og:type" content="website" />
                <meta property="og:url" content={`${appUrl}/privacy`} />
            </Head>
            <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <div className="mx-auto max-w-4xl px-6 py-12 lg:px-8 lg:py-16">
                    <Link
                        href="/"
                        className="mb-8 inline-block text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                    >
                        {__('\u2190 Back to home')}
                    </Link>

                    <h1 className="mb-8 text-4xl font-semibold">
                        {__('Privacy Policy')}
                    </h1>

                    <div className="prose prose-neutral dark:prose-invert max-w-none">
                        <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                            {__('Last updated:')}
                            {new Date().toLocaleDateString()}
                        </p>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('1. Data Controller')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'Whisper Money is the data controller responsible\n                                for your personal information.',
                                )}
                            </p>
                            <div className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <p>
                                    <strong>{__('Company Name:')}</strong>{' '}
                                    Whisper Money
                                </p>
                                <p>
                                    <strong>{__('Address:')}</strong> Calle Oca,
                                    Madrid - 28025, Spain
                                </p>
                                <p>
                                    <strong>{__('Email:')}</strong>
                                    {__('victor@whisper.money')}
                                </p>
                            </div>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('2. Information We Collect')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'We collect the following types of personal\n                                information:',
                                )}
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    <strong>
                                        {__('Account Information:')}
                                    </strong>
                                    {__(
                                        'Email\n                                    address, name, and password (encrypted)',
                                    )}
                                </li>
                                <li>
                                    <strong>{__('Financial Data:')}</strong>
                                    {__(
                                        'Transaction\n                                    details, budgets, categories, and other\n                                    financial information you manually enter\n                                    into the application',
                                    )}
                                </li>
                                <li>
                                    <strong>{__('Usage Information:')}</strong>{' '}
                                    {__(
                                        'Information about how you use our service,\n                                    including access times and features used',
                                    )}
                                </li>
                                <li>
                                    <strong>
                                        {__('Technical Information:')}
                                    </strong>
                                    {__(
                                        'IP\n                                    address, browser type, device information,\n                                    and operating system',
                                    )}
                                </li>
                            </ul>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('3. How We Use Your Information')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'We process your personal data for the following\n                                purposes:',
                                )}
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    To provide and maintain our personal finance
                                    tracking service
                                </li>
                                <li>
                                    {__(
                                        'To enable cloud synchronization of your\n                                    encrypted financial data across devices',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'To authenticate your access and protect your\n                                    account security',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'To process payments for premium features or\n                                    subscriptions',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'To send you service-related notifications,\n                                    updates, and security alerts via email',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'To improve and optimize our service based on\n                                    usage patterns',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'To comply with legal obligations and enforce\n                                    our terms',
                                    )}
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'The legal basis for processing your data\n                                includes: performance of our contract with you,\n                                your consent, our legitimate interests in\n                                improving the service, and compliance with legal\n                                obligations.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('4. Data Security and Encryption')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'We implement robust security measures to protect\n                                your personal information:',
                                )}
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    <strong>{__('Encryption at Rest:')}</strong>
                                    {__(
                                        ' All data is stored on secure servers with encryption at rest, protecting your information from unauthorized access',
                                    )}
                                </li>
                                <li>
                                    <strong>
                                        {__('Encryption in Transit:')}
                                    </strong>
                                    {__(
                                        ' All communications between your device and our servers are protected using TLS (Transport Layer Security)',
                                    )}
                                </li>
                                <li>
                                    <strong>
                                        {__('No Third-Party Data Sharing:')}
                                    </strong>
                                    {__(
                                        ' Your financial data is never shared with advertisers, data brokers, or any third party',
                                    )}
                                </li>
                                <li>
                                    <strong>{__('Access Controls:')}</strong>
                                    {__(
                                        ' Strict access controls and authentication mechanisms protect against unauthorized access',
                                    )}
                                </li>
                                <li>
                                    <strong>
                                        {__('Regular Security Audits:')}
                                    </strong>
                                    {__(
                                        ' We regularly review and update our security practices',
                                    )}
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'While we use industry-standard security\n                                measures, no method of transmission over the\n                                internet or electronic storage is 100% secure.\n                                We cannot guarantee absolute security but\n                                continuously work to protect your data.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('5. Third-Party Services')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'We use the following third-party services to\n                                operate our platform:',
                                )}
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    <strong>{__('Payment Processors:')}</strong>
                                    {__(
                                        'To\n                                    process subscription payments and\n                                    transactions. These processors have access\n                                    only to the information necessary to perform\n                                    their functions and are obligated to protect\n                                    your data',
                                    )}
                                </li>
                                <li>
                                    <strong>Email Service Providers:</strong>
                                    {__(
                                        'To\n                                    send transactional emails, password resets,\n                                    and service notifications',
                                    )}
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'We do not sell, trade, or rent your personal\n                                information to third parties for marketing\n                                purposes.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('6. Data Retention')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We retain your personal information for as long
                                as necessary to provide our services and fulfill
                                the purposes outlined in this Privacy Policy.
                                When you delete your account, we will delete or
                                anonymize your personal information within 30
                                days, except where we are required to retain it
                                for legal, accounting, or security purposes.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('7. International Data Transfers')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                Your data is primarily stored and processed
                                within the European Union. If we transfer data
                                outside the EU, we ensure appropriate safeguards
                                are in place, such as Standard Contractual
                                Clauses approved by the European Commission, to
                                protect your information in accordance with GDPR
                                requirements.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('8. Your Rights Under GDPR')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'As a user in the European Union, you have the\n                                following rights regarding your personal data:',
                                )}
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    <strong>{__('Right of Access:')}</strong>
                                    {__(
                                        'You can\n                                    request a copy of the personal data we hold\n                                    about you',
                                    )}
                                </li>
                                <li>
                                    <strong>
                                        {__('Right to Rectification:')}
                                    </strong>
                                    {__(
                                        'You\n                                    can request correction of inaccurate or\n                                    incomplete data',
                                    )}
                                </li>
                                <li>
                                    <strong>{__('Right to Erasure:')}</strong>
                                    {__(
                                        'You can\n                                    request deletion of your personal data\n                                    (right to be forgotten)',
                                    )}
                                </li>
                                <li>
                                    <strong>
                                        {__('Right to Data Portability:')}
                                    </strong>{' '}
                                    {__(
                                        'You can request your data in a structured,\n                                    machine-readable format',
                                    )}
                                </li>
                                <li>
                                    <strong>
                                        {__('Right to Restriction:')}
                                    </strong>
                                    {__(
                                        'You\n                                    can request restriction of processing in\n                                    certain circumstances',
                                    )}
                                </li>
                                <li>
                                    <strong>{__('Right to Object:')}</strong>
                                    {__(
                                        'You can\n                                    object to processing of your data based on\n                                    legitimate interests',
                                    )}
                                </li>
                                <li>
                                    <strong>
                                        {__('Right to Withdraw Consent:')}
                                    </strong>{' '}
                                    {__(
                                        'Where processing is based on consent, you\n                                    can withdraw it at any time',
                                    )}
                                </li>
                                <li>
                                    <strong>
                                        {__('Right to Lodge a Complaint:')}
                                    </strong>{' '}
                                    {__(
                                        'You can file a complaint with your local\n                                    data protection authority',
                                    )}
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'To exercise any of these rights, please contact\n                                us at victor@whisper.money. We will respond to\n                                your request within 30 days.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('9. Cookies and Tracking')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'We use essential cookies to maintain your\n                                session and ensure the proper functioning of our\n                                service. These cookies are necessary for the\n                                service to work and cannot be disabled. We do\n                                not use tracking cookies or analytics cookies\n                                without your explicit consent.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__("10. Children's Privacy")}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'Our service is not intended for users under the\n                                age of 16. We do not knowingly collect personal\n                                information from children. If you believe we\n                                have collected information from a child, please\n                                contact us immediately, and we will delete it.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('11. Changes to This Privacy Policy')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'We may update this Privacy Policy from time to\n                                time to reflect changes in our practices or for\n                                legal, operational, or regulatory reasons. When\n                                we make material changes, we will notify you by\n                                email and/or by posting a notice on our website\n                                at least 30 days before the changes take effect.\n                                Your continued use of the service after changes\n                                become effective constitutes acceptance of the\n                                updated policy.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('12. Contact Us')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'If you have any questions, concerns, or requests\n                                regarding this Privacy Policy or our data\n                                practices, please contact us:',
                                )}
                            </p>
                            <div className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <p>
                                    <strong>{__('Email:')}</strong>
                                    {__('victor@whisper.money')}
                                </p>
                                <p>
                                    <strong>{__('Address:')}</strong> Whisper
                                    Money, Calle Oca, Madrid - 28025, Spain
                                </p>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}
