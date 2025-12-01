import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Terms() {
    const { appUrl } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Terms of Service - Whisper Money">
                <meta
                    name="description"
                    content="Terms of service for Whisper Money. Review the rules and regulations for using our secure personal finance platform."
                />
                <link rel="canonical" href={`${appUrl}/terms`} />
                <meta name="robots" content="index, follow" />

                <meta
                    property="og:title"
                    content="Terms of Service - Whisper Money"
                />
                <meta
                    property="og:description"
                    content="Terms of service for Whisper Money. Review the rules and regulations for using our platform."
                />
                <meta property="og:type" content="website" />
                <meta property="og:url" content={`${appUrl}/terms`} />
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
                        Terms of Service
                    </h1>

                    <div className="prose prose-neutral dark:prose-invert max-w-none">
                        <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                            Last updated: {new Date().toLocaleDateString()}
                        </p>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                1. Service Provider
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                These Terms of Service govern your use of the
                                Whisper Money personal finance platform.
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
                                2. Acceptance of Terms
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                By creating an account or using Whisper Money,
                                you agree to be bound by these Terms of Service
                                and our Privacy Policy. If you do not agree to
                                these terms, you may not use our service. These
                                terms constitute a legally binding agreement
                                between you and Whisper Money.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                You must be at least 16 years old to use this
                                service. By using Whisper Money, you represent
                                and warrant that you meet this age requirement.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                3. Service Description
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                Whisper Money is a personal finance tracking
                                application that allows you to:
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    Manually record and track your financial
                                    transactions, budgets, and expenses
                                </li>
                                <li>
                                    Organize financial data with categories and
                                    custom labels
                                </li>
                                <li>
                                    Sync your encrypted financial data across
                                    multiple devices via cloud storage
                                </li>
                                <li>
                                    Access your data with end-to-end encryption
                                    to ensure privacy and security
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We reserve the right to modify, suspend, or
                                discontinue any part of the service at any time,
                                with or without notice. We will not be liable to
                                you or any third party for any modification,
                                suspension, or discontinuation of the service.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                4. User Accounts and Responsibilities
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                When you create an account with Whisper Money,
                                you agree to:
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    Provide accurate, current, and complete
                                    information during registration
                                </li>
                                <li>
                                    Maintain and promptly update your account
                                    information
                                </li>
                                <li>
                                    Maintain the security and confidentiality of
                                    your account credentials
                                </li>
                                <li>
                                    Accept responsibility for all activities
                                    that occur under your account
                                </li>
                                <li>
                                    Notify us immediately of any unauthorized
                                    access or security breach
                                </li>
                                <li>
                                    Use the service only for lawful purposes and
                                    in compliance with all applicable laws
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                You may not use the service to engage in any
                                illegal activity, transmit malicious code,
                                attempt to gain unauthorized access to our
                                systems, or interfere with the proper
                                functioning of the service.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                5. Data Ownership and License
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <strong>Your Data:</strong> You retain all
                                ownership rights to the financial data and
                                information you enter into Whisper Money. We do
                                not claim any ownership over your personal
                                financial information.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                By using our service, you grant us a limited
                                license to store, process, and transmit your
                                data solely for the purpose of providing the
                                service to you. This license terminates when you
                                delete your data or close your account.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                You are responsible for maintaining backups of
                                your data. While we implement reasonable backup
                                procedures, we recommend you export and save
                                copies of important data regularly.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                6. Intellectual Property Rights
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                The Whisper Money platform, including its
                                software, design, text, graphics, logos, and
                                other content (excluding your personal data), is
                                owned by Whisper Money and protected by
                                copyright, trademark, and other intellectual
                                property laws. You may not copy, modify,
                                distribute, sell, or lease any part of our
                                service or software without our explicit written
                                permission.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                The Whisper Money name and logo are trademarks
                                of Whisper Money. You may not use these
                                trademarks without our prior written consent.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                7. Payment Terms
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                If you subscribe to a paid plan or purchase
                                premium features:
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    You agree to pay all applicable fees as
                                    described at the time of purchase
                                </li>
                                <li>
                                    Payments are processed by third-party
                                    payment processors and subject to their
                                    terms
                                </li>
                                <li>
                                    Subscription fees are billed in advance on a
                                    recurring basis until cancelled
                                </li>
                                <li>
                                    All fees are non-refundable except as
                                    required by law or explicitly stated
                                    otherwise
                                </li>
                                <li>
                                    We reserve the right to change our pricing
                                    with 30 days advance notice to subscribers
                                </li>
                                <li>
                                    You are responsible for all taxes associated
                                    with your purchase
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                You may cancel your subscription at any time.
                                Upon cancellation, you will retain access until
                                the end of your current billing period, after
                                which your subscription will not renew.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                8. Termination
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                You may terminate your account at any time by
                                deleting your account through the application
                                settings or by contacting us. Upon termination,
                                your right to use the service will immediately
                                cease.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We may suspend or terminate your account if you
                                violate these Terms, engage in fraudulent
                                activity, or for any other reason at our
                                discretion. We will provide notice when
                                reasonably possible, but we reserve the right to
                                immediately terminate accounts in cases of
                                serious violations or legal requirements.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                Upon termination, we will delete your personal
                                data in accordance with our Privacy Policy and
                                applicable law, typically within 30 days.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                9. Disclaimers and Warranties
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <strong>Financial Advice Disclaimer:</strong>{' '}
                                Whisper Money is a tool for tracking and
                                organizing your financial information. It does
                                not provide financial, investment, tax, or legal
                                advice. You should consult with qualified
                                professionals for such advice. We are not
                                responsible for any financial decisions you make
                                based on data in the application.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <strong>Service Availability:</strong> The
                                service is provided "as is" and "as available"
                                without warranties of any kind. We do not
                                guarantee that the service will be
                                uninterrupted, error-free, or completely secure.
                                We strive to maintain high availability but do
                                not warrant that the service will meet your
                                specific requirements.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <strong>Data Accuracy:</strong> You are
                                responsible for the accuracy of the data you
                                enter. We do not verify or guarantee the
                                accuracy of your financial information. Any
                                calculations or reports generated by the service
                                are based on the data you provide.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                10. Limitation of Liability
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                To the maximum extent permitted by applicable
                                law, Whisper Money, its directors, employees,
                                and affiliates shall not be liable for any
                                indirect, incidental, special, consequential, or
                                punitive damages, including without limitation:
                                loss of profits, data, use, goodwill, or other
                                intangible losses resulting from:
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    Your use or inability to use the service
                                </li>
                                <li>
                                    Any unauthorized access to or use of our
                                    servers and/or personal information
                                </li>
                                <li>
                                    Any interruption or cessation of
                                    transmission to or from the service
                                </li>
                                <li>
                                    Any bugs, viruses, or similar harmful
                                    components transmitted through the service
                                </li>
                                <li>
                                    Any errors or omissions in content or any
                                    loss or damage incurred from using content
                                </li>
                                <li>
                                    Financial decisions made based on data in
                                    the application
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                Our total liability to you for all claims
                                arising from or related to the service shall not
                                exceed the amount you paid us in the 12 months
                                prior to the claim, or €100, whichever is
                                greater.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                Nothing in these Terms excludes or limits our
                                liability for death or personal injury caused by
                                negligence, fraud, or any liability that cannot
                                be excluded or limited under applicable law.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                11. Indemnification
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                You agree to indemnify, defend, and hold
                                harmless Whisper Money and its officers,
                                directors, employees, and agents from any
                                claims, liabilities, damages, losses, and
                                expenses, including reasonable legal fees,
                                arising out of or related to your use of the
                                service, violation of these Terms, or violation
                                of any rights of another party.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                12. Governing Law and Dispute Resolution
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                These Terms shall be governed by and construed
                                in accordance with the laws of Spain, without
                                regard to its conflict of law provisions. You
                                agree to submit to the exclusive jurisdiction of
                                the courts located in Madrid, Spain for the
                                resolution of any disputes.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                For users in the European Union, nothing in
                                these Terms affects your rights as a consumer
                                under applicable consumer protection laws,
                                including your right to bring proceedings in
                                your country of residence.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We encourage you to contact us directly at
                                victor@whisper.money to resolve any disputes
                                before initiating legal proceedings.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                13. Changes to Terms
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We reserve the right to modify or replace these
                                Terms at any time. If a revision is material, we
                                will provide at least 30 days notice prior to
                                the new terms taking effect by sending you an
                                email notification and/or posting a notice on
                                our website.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                Your continued use of the service after the
                                effective date of revised Terms constitutes your
                                acceptance of the changes. If you do not agree
                                to the new terms, you must stop using the
                                service and may delete your account.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                14. Severability
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                If any provision of these Terms is found to be
                                invalid, illegal, or unenforceable, the
                                remaining provisions shall continue in full
                                force and effect. The invalid provision shall be
                                modified to the minimum extent necessary to make
                                it valid and enforceable.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                15. Entire Agreement
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                These Terms, together with our Privacy Policy,
                                constitute the entire agreement between you and
                                Whisper Money regarding the use of our service
                                and supersede any prior agreements or
                                understandings, whether written or oral.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                16. Contact Us
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                If you have any questions, concerns, or feedback
                                about these Terms of Service, please contact us:
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
