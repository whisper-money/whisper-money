import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Privacy() {
    const { appUrl } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Privacy Policy - Whisper Money">
                <meta
                    name="description"
                    content="Privacy policy for Whisper Money. Learn how we collect, use, and protect your personal information with end-to-end encryption."
                />
                <link rel="canonical" href={`${appUrl}/privacy`} />
                <meta name="robots" content="index, follow" />

                <meta
                    property="og:title"
                    content="Privacy Policy - Whisper Money"
                />
                <meta
                    property="og:description"
                    content="Privacy policy for Whisper Money. Learn how we collect, use, and protect your personal information."
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
                        ← Back to home
                    </Link>

                    <h1 className="mb-8 text-4xl font-semibold">
                        Privacy Policy
                    </h1>

                    <div className="prose prose-neutral dark:prose-invert max-w-none">
                        <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                            Last updated: {new Date().toLocaleDateString()}
                        </p>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                1. Data Controller
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                Whisper Money is the data controller responsible
                                for your personal information.
                            </p>
                            <div className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <p>
                                    <strong>Company Name:</strong> Whisper Money
                                </p>
                                <p>
                                    <strong>Address:</strong> Calle Oca, Madrid
                                    - 28025, Spain
                                </p>
                                <p>
                                    <strong>Email:</strong> victor@whisper.money
                                </p>
                            </div>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                2. Information We Collect
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We collect the following types of personal
                                information:
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    <strong>Account Information:</strong> Email
                                    address, name, and password (encrypted)
                                </li>
                                <li>
                                    <strong>Financial Data:</strong> Transaction
                                    details, budgets, categories, and other
                                    financial information you manually enter
                                    into the application
                                </li>
                                <li>
                                    <strong>Usage Information:</strong>{' '}
                                    Information about how you use our service,
                                    including access times and features used
                                </li>
                                <li>
                                    <strong>Technical Information:</strong> IP
                                    address, browser type, device information,
                                    and operating system
                                </li>
                            </ul>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                3. How We Use Your Information
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We process your personal data for the following
                                purposes:
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    To provide and maintain our personal finance
                                    tracking service
                                </li>
                                <li>
                                    To enable cloud synchronization of your
                                    encrypted financial data across devices
                                </li>
                                <li>
                                    To authenticate your access and protect your
                                    account security
                                </li>
                                <li>
                                    To process payments for premium features or
                                    subscriptions
                                </li>
                                <li>
                                    To send you service-related notifications,
                                    updates, and security alerts via email
                                </li>
                                <li>
                                    To improve and optimize our service based on
                                    usage patterns
                                </li>
                                <li>
                                    To comply with legal obligations and enforce
                                    our terms
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                The legal basis for processing your data
                                includes: performance of our contract with you,
                                your consent, our legitimate interests in
                                improving the service, and compliance with legal
                                obligations.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                4. Data Security and Encryption
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We implement robust security measures to protect
                                your personal information:
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    <strong>End-to-End Encryption:</strong> Your
                                    financial data is encrypted on your device
                                    before being transmitted to our servers,
                                    ensuring that only you can access your
                                    information
                                </li>
                                <li>
                                    <strong>Secure Storage:</strong> All data is
                                    stored on secure servers with encryption at
                                    rest
                                </li>
                                <li>
                                    <strong>Access Controls:</strong> Strict
                                    access controls and authentication
                                    mechanisms protect against unauthorized
                                    access
                                </li>
                                <li>
                                    <strong>Regular Security Audits:</strong> We
                                    regularly review and update our security
                                    practices
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                While we use industry-standard security
                                measures, no method of transmission over the
                                internet or electronic storage is 100% secure.
                                We cannot guarantee absolute security but
                                continuously work to protect your data.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                5. Third-Party Services
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We use the following third-party services to
                                operate our platform:
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    <strong>Payment Processors:</strong> To
                                    process subscription payments and
                                    transactions. These processors have access
                                    only to the information necessary to perform
                                    their functions and are obligated to protect
                                    your data
                                </li>
                                <li>
                                    <strong>Email Service Providers:</strong> To
                                    send transactional emails, password resets,
                                    and service notifications
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We do not sell, trade, or rent your personal
                                information to third parties for marketing
                                purposes.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                6. Data Retention
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
                                7. International Data Transfers
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
                                8. Your Rights Under GDPR
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                As a user in the European Union, you have the
                                following rights regarding your personal data:
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    <strong>Right of Access:</strong> You can
                                    request a copy of the personal data we hold
                                    about you
                                </li>
                                <li>
                                    <strong>Right to Rectification:</strong> You
                                    can request correction of inaccurate or
                                    incomplete data
                                </li>
                                <li>
                                    <strong>Right to Erasure:</strong> You can
                                    request deletion of your personal data
                                    (right to be forgotten)
                                </li>
                                <li>
                                    <strong>Right to Data Portability:</strong>{' '}
                                    You can request your data in a structured,
                                    machine-readable format
                                </li>
                                <li>
                                    <strong>Right to Restriction:</strong> You
                                    can request restriction of processing in
                                    certain circumstances
                                </li>
                                <li>
                                    <strong>Right to Object:</strong> You can
                                    object to processing of your data based on
                                    legitimate interests
                                </li>
                                <li>
                                    <strong>Right to Withdraw Consent:</strong>{' '}
                                    Where processing is based on consent, you
                                    can withdraw it at any time
                                </li>
                                <li>
                                    <strong>Right to Lodge a Complaint:</strong>{' '}
                                    You can file a complaint with your local
                                    data protection authority
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                To exercise any of these rights, please contact
                                us at victor@whisper.money. We will respond to
                                your request within 30 days.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                9. Cookies and Tracking
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We use essential cookies to maintain your
                                session and ensure the proper functioning of our
                                service. These cookies are necessary for the
                                service to work and cannot be disabled. We do
                                not use tracking cookies or analytics cookies
                                without your explicit consent.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                10. Children's Privacy
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                Our service is not intended for users under the
                                age of 16. We do not knowingly collect personal
                                information from children. If you believe we
                                have collected information from a child, please
                                contact us immediately, and we will delete it.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                11. Changes to This Privacy Policy
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We may update this Privacy Policy from time to
                                time to reflect changes in our practices or for
                                legal, operational, or regulatory reasons. When
                                we make material changes, we will notify you by
                                email and/or by posting a notice on our website
                                at least 30 days before the changes take effect.
                                Your continued use of the service after changes
                                become effective constitutes acceptance of the
                                updated policy.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                12. Contact Us
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                If you have any questions, concerns, or requests
                                regarding this Privacy Policy or our data
                                practices, please contact us:
                            </p>
                            <div className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <p>
                                    <strong>Email:</strong> victor@whisper.money
                                </p>
                                <p>
                                    <strong>Address:</strong> Whisper Money,
                                    Calle Oca, Madrid - 28025, Spain
                                </p>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}
