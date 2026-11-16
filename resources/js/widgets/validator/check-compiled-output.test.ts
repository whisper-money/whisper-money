// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { checkCompiledOutput } from './check-compiled-output';

const codes = (js: string) =>
    checkCompiledOutput(js).map((problem) => problem.code);

describe('checkCompiledOutput', () => {
    it('accepts named imports of the externals at the top and a default export', () => {
        expect(
            checkCompiledOutput(
                'import{useState as a}from"react";import{jsx as j}from"react/jsx-runtime";function W(){a();return j("div",{})}export{W as default};',
            ),
        ).toEqual([]);
    });

    it.each([
        [
            'an import that is not an external',
            'import{a}from"left-pad";export{a as default};',
            'output-import',
        ],
        [
            'a default import',
            'import React from"react";export{React as default};',
            'output-import',
        ],
        [
            'a namespace import',
            'import*as R from"react";export{R as default};',
            'output-import',
        ],
        [
            'an import after code',
            'const x=1;import{useState}from"react";export{x as default};',
            'output-import',
        ],
        [
            'an import the loader cannot read',
            'import{/* } */useState}from"react";export{useState as default};',
            'output-import',
        ],
        [
            'a dynamic import()',
            'function W(){return import("x")}export{W as default};',
            'output-import',
        ],
        [
            'a require()',
            'function W(){return require("x")}export{W as default};',
            'output-import',
        ],
        [
            'a re-export',
            'export*from"react";function W(){}export{W as default};',
            'output-import',
        ],
        ['no default export', 'function W(){}export{W};', 'output-export'],
    ])('rejects %s', (_name, js, code) => {
        expect(codes(js)).toContain(code);
    });
});
