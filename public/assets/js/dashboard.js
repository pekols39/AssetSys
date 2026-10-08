document.addEventListener('DOMContentLoaded', () => {

    const categoryCanvas =
        document.getElementById('assetsByCategoryChart');

    if (!categoryCanvas) {
        return;
    }

    const categoryLabels =
        window.dashboardData?.assetsByCategory?.labels ?? [];

    const categoryValues =
        window.dashboardData?.assetsByCategory?.values ?? [];

    new Chart(categoryCanvas, {
        type: 'doughnut',

        data: {
            labels: categoryLabels,

            datasets: [
                {
                    data: categoryValues,

                    borderWidth: 0
                }
            ]
        },

        options: {
            responsive: true,

            maintainAspectRatio: false,

            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    const locationCanvas =
        document.getElementById('assetsByLocationChart');

    if (locationCanvas) {

        const locationLabels =
            window.dashboardData?.assetsByLocation?.labels ?? [];

        const locationValues =
            window.dashboardData?.assetsByLocation?.values ?? [];

        new Chart(locationCanvas, {
            type: 'bar',

            data: {
                labels: locationLabels,

                datasets: [
                    {
                        label: 'Assets',
                        data: locationValues,
                        borderWidth: 1
                    }
                ]
            },

            options: {
                responsive: true,

                maintainAspectRatio: false,

                scales: {
                    y: {
                        beginAtZero: true,

                        ticks: {
                            precision: 0
                        }
                    }
                },

                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

    }
    
    const conditionCanvas =
        document.getElementById('assetsByConditionChart');

    if (conditionCanvas) {

        const conditionLabels =
            window.dashboardData?.assetsByCondition?.labels ?? [];

        const conditionValues =
            window.dashboardData?.assetsByCondition?.values ?? [];

        new Chart(conditionCanvas, {
            type: 'doughnut',

            data: {
                labels: conditionLabels,

                datasets: [
                    {
                        data: conditionValues,
                        borderWidth: 0,
                        hoverOffset: 6
                    }
                ]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                cutout: '62%',

                plugins: {
                    legend: {
                        position: 'bottom'
                    },

                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const label = context.label || 'Condition';
                                const value = Number(context.raw ?? 0);

                                return `${ label }: ${ value.toLocaleString('en-PH') } assets`;
                            }
                        }
                    }
                }
            }
        });

    }

    const financialMaintenanceCanvas =
        document.getElementById(
            'financialMaintenanceTrendsChart'
        );

    if (financialMaintenanceCanvas) {

        const depreciationData =
            window.dashboardData?.depreciationByYear ?? {};

        const maintenanceData =
            window.dashboardData?.maintenanceCosts ?? {};

        const repairData =
            window.dashboardData?.repairCosts ?? {};

        // Combine all years while preserving chronological order.
        const years = [
            ...new Set([
                ...(depreciationData.labels ?? []),
                ...(maintenanceData.labels ?? []),
                ...(repairData.labels ?? [])
            ])
        ].sort((a, b) => Number(a) - Number(b));

        const alignValues = (dataset) => {

            const labels = dataset.labels ?? [];
            const values = dataset.values ?? [];

            const valueByYear = new Map(
                labels.map((year, index) => [
                    String(year),
                    Number(values[index] ?? 0)
                ])
            );

            return years.map(
                (year) => valueByYear.get(String(year)) ?? 0
            );
        };

        const formatPeso = (value, decimals = 0) => {

            return '₱' + Number(value).toLocaleString(
                'en-PH',
                {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                }
            );
        };

        new Chart(financialMaintenanceCanvas, {

            type: 'line',

            data: {
                labels: years,

                datasets: [
                    {
                        label: 'Depreciation',
                        data: alignValues(depreciationData),
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        fill: false
                    },
                    {
                        label: 'Maintenance Cost',
                        data: alignValues(maintenanceData),
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        fill: false
                    },
                    {
                        label: 'Repair Cost',
                        data: alignValues(repairData),
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        fill: false
                    }
                ]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                interaction: {
                    mode: 'index',
                    intersect: false
                },

                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Year'
                        }
                    },

                    y: {
                        beginAtZero: true,

                        ticks: {
                            callback: (value) => formatPeso(value)
                        }
                    }
                },

                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    },

                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                return `${context.dataset.label}: ${formatPeso(context.raw, 2)
                                    }`;
                            }
                        }
                    }
                }
            }

        });

    }


});