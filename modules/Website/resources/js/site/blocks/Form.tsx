import type { BlockDefinition } from '../types';
import { QUESTIONS_FIELD, QuoteFormView, type QuoteFormProps } from './QuoteForm';

export interface FormProps extends QuoteFormProps {
  purpose: 'enquiry' | 'waiting_list' | 'interest';
}

/** A general form — enquiries, waiting lists, registrations of interest — with the business's own questions. */
export const Form: BlockDefinition<FormProps> = {
  type: 'Form',
  version: 1,
  label: 'Form',
  description:
    'Enquiries, a waiting list or registrations of interest, with your own questions; replies arrive in your Enquiries.',
  fields: {
    purpose: {
      kind: 'select',
      label: 'What’s it for?',
      options: [
        { value: 'enquiry', label: 'Enquiries' },
        { value: 'waiting_list', label: 'A waiting list' },
        { value: 'interest', label: 'Registrations of interest' },
      ],
    },
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    questions: QUESTIONS_FIELD,
    detailsLabel: { kind: 'text', label: 'Last question (optional for visitors)', max: 80 },
    submitLabel: { kind: 'text', inline: true, label: 'Button', max: 30 },
    leads: {
      kind: 'select',
      label: 'Open a lead for each reply',
      options: [
        { value: 'no', label: 'No' },
        { value: 'yes', label: 'Yes' },
      ],
    },
    askNews: {
      kind: 'select',
      label: 'Ask if they’d like news and offers',
      options: [
        { value: 'yes', label: 'Yes' },
        { value: 'no', label: 'No' },
      ],
    },
  },
  defaults: {
    purpose: 'enquiry',
    heading: 'Get in touch',
    intro: 'Leave your details and we’ll get back to you.',
    services: [],
    questions: [],
    detailsLabel: 'Anything else?',
    submitLabel: 'Send',
    leads: 'no',
    askNews: 'yes',
  },
  render: (p, site) => (
    <QuoteFormView p={p} action={site.formAction} editing={site.editing} kind={p.purpose} />
  ),
};
