import { Country, State } from 'country-state-city';

document.addEventListener('DOMContentLoaded', () => {
    const countrySelect = document.querySelector('select[name="country"]');
    const stateSelect = document.querySelector('select[name="state"]');

    // If this page has no country/state fields, do nothing.
    if (!countrySelect || !stateSelect) return;

    // Populate the Country dropdown once, on page load.
    countrySelect.innerHTML = '<option value="">--Select--</option>';
    Country.getAllCountries().forEach((c) => {
        const opt = document.createElement('option');
        opt.value = c.name;       // human-readable value actually saved to DB
        opt.dataset.iso = c.isoCode; // hidden ISO code, used only to look up states
        opt.textContent = c.name;
        countrySelect.appendChild(opt);
    });

    // When a country is picked, populate matching states/provinces.
    countrySelect.addEventListener('change', () => {
        const selectedOption = countrySelect.selectedOptions[0];
        const isoCode = selectedOption ? selectedOption.dataset.iso : null;

        stateSelect.innerHTML = '<option value="">--Select--</option>';
        if (!isoCode) return;

        const states = State.getStatesOfCountry(isoCode);
        if (states.length === 0) {
            stateSelect.innerHTML = '<option value="">N/A</option>';
            return;
        }
        states.forEach((s) => {
            const opt = document.createElement('option');
            opt.value = s.name;
            opt.textContent = s.name;
            stateSelect.appendChild(opt);
        });
    });
});