import { isValidElement, ReactNode } from 'react';

export default function HeadingSmall({
    title,
    description,
}: {
    title: string;
    description?: string | ReactNode;
}) {
    return (
        <header>
            <h3 className="mb-0.5 text-base font-medium">{title}</h3>
            {typeof description === 'string' && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
            {isValidElement(description) && description}
        </header>
    );
}
