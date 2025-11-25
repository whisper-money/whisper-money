import { Head, Link, usePage } from '@inertiajs/react';
import { type SharedData } from '@/types';

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

                <meta property="og:title" content="Privacy Policy - Whisper Money" />
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

                    <div className="prose prose-neutral max-w-none dark:prose-invert">
                        <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                            Last updated: {new Date().toLocaleDateString()}
                        </p>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Information We Collect
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We collect information you provide directly to
                                us, such as when you create an account, submit
                                your email address, or communicate with us. This
                                may include your name, email address, and any
                                other information you choose to provide.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                How We Use Your Information
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We use the information we collect to provide,
                                maintain, and improve our services, to
                                communicate with you, and to comply with legal
                                obligations.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Information Sharing
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We do not sell, trade, or otherwise transfer
                                your personal information to third parties
                                without your consent, except as required by law
                                or as necessary to provide our services.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Data Security
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                We implement appropriate technical and
                                organizational measures to protect your personal
                                information against unauthorized access, loss, or
                                misuse.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Your Rights
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                You have the right to access, correct, or delete
                                your personal information. You may also object to
                                or restrict certain processing of your data.
                                Please contact us to exercise these rights.
                            </p>
                        </section>

                        <section className="mt-8">
                            <h2 className="mb-4 text-2xl font-semibold">
                                Contact Us
                            </h2>
                            <p className="mb-4 text-[#706f6c] dark:text-[#A1A09A]">
                                If you have any questions about this Privacy
                                Policy, please contact us through our website.
                            </p>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}

