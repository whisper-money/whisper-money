import { Head, Link } from '@inertiajs/react';

export default function Terms() {
    return (
        <>
            <Head title="Terms of Service">
                <meta
                    name="description"
                    content="Our terms of service outline the rules and regulations for using our platform."
                />
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

                    <div className="prose prose-neutral max-w-none dark:prose-invert">
                        <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                            Last updated: {new Date().toLocaleDateString()}
                        </p>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Acceptance of Terms
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                By accessing and using this service, you accept
                                and agree to be bound by the terms and provisions
                                of this agreement. If you do not agree to these
                                terms, please do not use our service.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Use of Service
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                You agree to use this service only for lawful
                                purposes and in accordance with these Terms. You
                                agree not to use the service in any way that
                                could damage, disable, overburden, or impair the
                                service.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                User Accounts
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                When you create an account with us, you must
                                provide accurate, complete, and current
                                information. You are responsible for maintaining
                                the confidentiality of your account and password.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Intellectual Property
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                The service and its original content, features,
                                and functionality are owned by us and are
                                protected by international copyright, trademark,
                                patent, trade secret, and other intellectual
                                property laws.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Limitation of Liability
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                In no event shall we be liable for any indirect,
                                incidental, special, consequential, or punitive
                                damages resulting from your use of or inability
                                to use the service.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Changes to Terms
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We reserve the right to modify or replace these
                                Terms at any time. If a revision is material, we
                                will provide at least 30 days notice prior to any
                                new terms taking effect.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Contact Us
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                If you have any questions about these Terms,
                                please contact us through our website.
                            </p>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}

