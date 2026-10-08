/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Jest config for the Audiolog frontend unit tests.
module.exports = {
    testEnvironment: 'jsdom',
    testMatch: ['<rootDir>/tests/js/**/*.test.js'],
    rootDir: '..',
    transform: {},
};
