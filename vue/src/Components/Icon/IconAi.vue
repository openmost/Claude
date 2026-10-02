<template>
  <!-- eslint-disable max-len -->
  <svg
    v-if="aiName === 'claude'"
    xmlns="http://www.w3.org/2000/svg"
    width="18"
    height="18"
    viewBox="0 0 24 24"
    fill="none"
    aria-hidden="true"
  >
    <path
      d="M12 9.6V1.8M13.57 10.19L16.99 6.26M14.28 11.26L21.7 8.85M14.28 12.74L19.23 14.35M13.24 14.06L17.25 20.74M12 14.4V19.6M10.59 13.94L6 20.25M9.66 12.54L4.59 13.71M9.72 11.26L2.3 8.85M10.59 10.06L7.53 5.85"
      stroke="currentColor"
      stroke-width="2.2"
      stroke-linecap="round"
    />
  </svg>
  <!-- eslint-enable max-len -->
</template>

<script lang="ts">
import { defineComponent } from 'vue';

export default defineComponent({
  props: {
    aiName: {
      type: String,
      required: true,
    },
  },
});
</script>
