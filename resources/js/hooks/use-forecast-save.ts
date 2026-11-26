import { recomputeForecast } from '@/lib/forecast/edits';
import { type ProjectionInput } from '@/lib/forecast/types';
import { type ForecastData, type ForecastPageProps } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { type RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

type RouteDefinition = {
    url: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

export interface OptimisticEdit {
    /** The projection input after the edit. */
    input: ProjectionInput;
    /** The page before its projection is recomputed, when the edit changes more than the input. */
    forecast?: ForecastData;
    /** The lines whose amounts the edit changes. */
    itemIds?: string[];
}

export interface ForecastSave {
    save: (
        route: RouteDefinition,
        data: RequestPayload,
        edit: OptimisticEdit | null,
        onSaved?: () => void,
        options?: { toastErrors?: boolean },
    ) => void;
    processing: boolean;
    errors: Record<string, string>;
    clearErrors: () => void;
}

/**
 * Saves an edit of the forecast and shows its effect at once: the page is
 * recomputed on the client with the TypeScript engine while the request is
 * in flight, then replaced by the forecast the server answers with (only
 * `forecast` and `projectionInput` reload). A request that fails puts the
 * page back as it was, and its validation errors are returned for the
 * sheet to show, or toasted when no sheet is open to show them.
 */
export function useForecastSave(): ForecastSave {
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const save: ForecastSave['save'] = (
        route,
        data,
        edit,
        onSaved,
        options,
    ) => {
        setErrors({});
        router.visit(route.url, {
            method: route.method,
            data,
            only: ['forecast', 'projectionInput'],
            preserveScroll: true,
            preserveState: true,
            optimistic:
                edit === null
                    ? undefined
                    : (props) => {
                          const { forecast } =
                              props as unknown as ForecastPageProps;

                          if (forecast.account === null) {
                              return {};
                          }

                          return {
                              forecast: recomputeForecast(
                                  edit.forecast ?? forecast,
                                  edit.input,
                                  edit.itemIds,
                              ),
                              projectionInput: edit.input,
                          };
                      },
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onSaved?.(),
            onError: (failed) => {
                setErrors(failed);

                const messages = Object.values(failed);

                if (messages.length === 0 || options?.toastErrors) {
                    toast.error(
                        messages[0] ?? __('The forecast could not be saved.'),
                    );
                }
            },
        });
    };

    return {
        save,
        processing,
        errors,
        clearErrors: () => setErrors({}),
    };
}
