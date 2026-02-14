import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Terms() {
    const { appUrl } = usePage<SharedData>().props;

    return (
        <>
            <Head title={__('Terms of Service - Whisper Money')}>
                <meta
                    name="description"
                    content={__(
                        'Terms of service for Whisper Money. Review the rules and regulations for using our secure personal finance platform.',
                    )}
                />

                <link rel="canonical" href={`${appUrl}/terms`} />
                <meta name="robots" content={__('index, follow')} />

                <meta
                    property="og:title"
                    content={__('Terms of Service - Whisper Money')}
                />

                <meta
                    property="og:description"
                    content={__(
                        'Terms of service for Whisper Money. Review the rules and regulations for using our platform.',
                    )}
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
                        {__('\u2190 Back to home')}
                    </Link>

                    <h1 className="mb-8 text-4xl font-semibold">
                        {__('Terms of Service')}
                    </h1>

                    <div className="prose prose-neutral dark:prose-invert max-w-none">
                        <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                            {__('Last updated:')}
                            {new Date().toLocaleDateString()}
                        </p>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                1. Service Provider
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'These Terms of Service govern your use of the\n                                Whisper Money personal finance platform.',
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
                                {__('2. Acceptance of Terms')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'By creating an account or using Whisper Money,\n                                you agree to be bound by these Terms of Service\n                                and our Privacy Policy. If you do not agree to\n                                these terms, you may not use our service. These\n                                terms constitute a legally binding agreement\n                                between you and Whisper Money.',
                                )}
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'You must be at least 16 years old to use this\n                                service. By using Whisper Money, you represent\n                                and warrant that you meet this age requirement.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('3. Service Description')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'Whisper Money is a personal finance tracking\n                                application that allows you to:',
                                )}
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    {__(
                                        'Manually record and track your financial\n                                    transactions, budgets, and expenses',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Organize financial data with categories and\n                                    custom labels',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Sync your encrypted financial data across\n                                    multiple devices via cloud storage',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Access your data securely—your information is never shared with third parties',
                                    )}
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'We reserve the right to modify, suspend, or\n                                discontinue any part of the service at any time,\n                                with or without notice. We will not be liable to\n                                you or any third party for any modification,\n                                suspension, or discontinuation of the service.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('4. User Accounts and Responsibilities')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'When you create an account with Whisper Money,\n                                you agree to:',
                                )}
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    Provide accurate, current, and complete
                                    information during registration
                                </li>
                                <li>
                                    {__(
                                        'Maintain and promptly update your account\n                                    information',
                                    )}
                                </li>
                                <li>
                                    Maintain the security and confidentiality of
                                    your account credentials
                                </li>
                                <li>
                                    {__(
                                        'Accept responsibility for all activities\n                                    that occur under your account',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Notify us immediately of any unauthorized\n                                    access or security breach',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Use the service only for lawful purposes and\n                                    in compliance with all applicable laws',
                                    )}
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'You may not use the service to engage in any\n                                illegal activity, transmit malicious code,\n                                attempt to gain unauthorized access to our\n                                systems, or interfere with the proper\n                                functioning of the service.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('5. Data Ownership and License')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <strong>{__('Your Data:')}</strong>
                                {__(
                                    'You retain all\n                                ownership rights to the financial data and\n                                information you enter into Whisper Money. We do\n                                not claim any ownership over your personal\n                                financial information.',
                                )}
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                By using our service, you grant us a limited
                                license to store, process, and transmit your
                                data solely for the purpose of providing the
                                service to you. This license terminates when you
                                delete your data or close your account.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'You are responsible for maintaining backups of\n                                your data. While we implement reasonable backup\n                                procedures, we recommend you export and save\n                                copies of important data regularly.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('6. Intellectual Property Rights')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'The Whisper Money platform, including its\n                                software, design, text, graphics, logos, and\n                                other content (excluding your personal data), is\n                                owned by Whisper Money and protected by\n                                copyright, trademark, and other intellectual\n                                property laws. You may not copy, modify,\n                                distribute, sell, or lease any part of our\n                                service or software without our explicit written\n                                permission.',
                                )}
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'The Whisper Money name and logo are trademarks\n                                of Whisper Money. You may not use these\n                                trademarks without our prior written consent.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('7. Payment Terms')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                If you subscribe to a paid plan or purchase
                                premium features:
                            </p>
                            <ul className="mb-4 list-disc pl-6 text-[#706f6c] dark:text-[#A1A09A]">
                                <li>
                                    {__(
                                        'You agree to pay all applicable fees as\n                                    described at the time of purchase',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Payments are processed by third-party\n                                    payment processors and subject to their\n                                    terms',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Subscription fees are billed in advance on a\n                                    recurring basis until cancelled',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'All fees are non-refundable except as\n                                    required by law or explicitly stated\n                                    otherwise',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'We reserve the right to change our pricing\n                                    with 30 days advance notice to subscribers',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'You are responsible for all taxes associated\n                                    with your purchase',
                                    )}
                                </li>
                            </ul>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'You may cancel your subscription at any time.\n                                Upon cancellation, you will retain access until\n                                the end of your current billing period, after\n                                which your subscription will not renew.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('8. Termination')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'You may terminate your account at any time by\n                                deleting your account through the application\n                                settings or by contacting us. Upon termination,\n                                your right to use the service will immediately\n                                cease.',
                                )}
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
                                {__(
                                    'Upon termination, we will delete your personal\n                                data in accordance with our Privacy Policy and\n                                applicable law, typically within 30 days.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('9. Disclaimers and Warranties')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <strong>
                                    {__('Financial Advice Disclaimer:')}
                                </strong>{' '}
                                Whisper Money is a tool for tracking and
                                organizing your financial information. It does
                                not provide financial, investment, tax, or legal
                                advice. You should consult with qualified
                                professionals for such advice. We are not
                                responsible for any financial decisions you make
                                based on data in the application.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <strong>{__('Service Availability:')}</strong>{' '}
                                The service is provided "as is" and "as
                                available" without warranties of any kind. We do
                                not guarantee that the service will be
                                uninterrupted, error-free, or completely secure.
                                We strive to maintain high availability but do
                                not warrant that the service will meet your
                                specific requirements.
                            </p>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                <strong>{__('Data Accuracy:')}</strong> You are
                                responsible for the accuracy of the data you
                                enter. We do not verify or guarantee the
                                accuracy of your financial information. Any
                                calculations or reports generated by the service
                                are based on the data you provide.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('10. Limitation of Liability')}
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
                                    {__(
                                        'Your use or inability to use the service',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Any unauthorized access to or use of our\n                                    servers and/or personal information',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Any interruption or cessation of\n                                    transmission to or from the service',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Any bugs, viruses, or similar harmful\n                                    components transmitted through the service',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Any errors or omissions in content or any\n                                    loss or damage incurred from using content',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Financial decisions made based on data in\n                                    the application',
                                    )}
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
                                {__(
                                    'Nothing in these Terms excludes or limits our\n                                liability for death or personal injury caused by\n                                negligence, fraud, or any liability that cannot\n                                be excluded or limited under applicable law.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('11. Indemnification')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'You agree to indemnify, defend, and hold\n                                harmless Whisper Money and its officers,\n                                directors, employees, and agents from any\n                                claims, liabilities, damages, losses, and\n                                expenses, including reasonable legal fees,\n                                arising out of or related to your use of the\n                                service, violation of these Terms, or violation\n                                of any rights of another party.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('12. Governing Law and Dispute Resolution')}
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
                                {__(
                                    'We encourage you to contact us directly at\n                                victor@whisper.money to resolve any disputes\n                                before initiating legal proceedings.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('13. Changes to Terms')}
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
                                {__(
                                    'Your continued use of the service after the\n                                effective date of revised Terms constitutes your\n                                acceptance of the changes. If you do not agree\n                                to the new terms, you must stop using the\n                                service and may delete your account.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('14. Severability')}
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
                                {__('15. Entire Agreement')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'These Terms, together with our Privacy Policy,\n                                constitute the entire agreement between you and\n                                Whisper Money regarding the use of our service\n                                and supersede any prior agreements or\n                                understandings, whether written or oral.',
                                )}
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                {__('16. Contact Us')}
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'If you have any questions, concerns, or feedback\n                                about these Terms of Service, please contact us:',
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
