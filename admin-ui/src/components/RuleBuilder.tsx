/**
 * RuleBuilder — a visual rule editor for smart playlists.
 *
 * Renders a list of rule rows, each with a field selector, operator selector,
 * and value input. Supports AND/OR grouping of rules. Rules are rendered as
 * React text content — no `dangerouslySetInnerHTML`.
 *
 * @since 3.3
 */
import { type ChangeEvent } from 'react';
import {
  RULE_FIELDS,
  STRING_OPS,
  NUMERIC_OPS,
  type Rule,
  type RuleGroup,
} from '../api/smartPlaylists';

/** Fields that use string operators. */
const STRING_FIELDS = ['title', 'genre', 'media_type'] as const;

/** Fields that use numeric operators. */
const NUMERIC_FIELDS = ['year', 'rating', 'runtime', 'added_at', 'play_count'] as const;

export interface RuleBuilderProps {
  /** Current rule group (and/or at top level). */
  value: RuleGroup;
  /** Called when rules change. */
  onChange: (group: RuleGroup) => void;
  /** Disable the control. */
  disabled?: boolean;
}

export function RuleBuilder({
  value,
  onChange,
  disabled = false,
}: RuleBuilderProps): JSX.Element {
  const handleLogicChange = (logic: 'and' | 'or'): void => {
    onChange({ ...value, logic });
  };

  const handleRuleChange = (index: number, rule: Rule): void => {
    const newRules = [...value.rules];
    // If field type changed, reset operator to a valid one for the new field type
    const oldRule = newRules[index] as Rule;
    const newField = rule.field;
    const isOldNumeric = NUMERIC_FIELDS.includes(oldRule.field as typeof NUMERIC_FIELDS[number]);
    const isNewNumeric = NUMERIC_FIELDS.includes(newField as typeof NUMERIC_FIELDS[number]);

    if (isOldNumeric !== isNewNumeric) {
      // Field type changed, reset operator
      rule.op = isNewNumeric ? 'eq' : 'contains';
    }

    newRules[index] = rule;
    onChange({ ...value, rules: newRules });
  };

  const handleAddRule = (): void => {
    const newRule: Rule = { field: 'title', op: 'contains', value: '' };
    onChange({ ...value, rules: [...value.rules, newRule] });
  };

  const handleDeleteRule = (index: number): void => {
    const newRules = value.rules.filter((_, i) => i !== index);
    onChange({ ...value, rules: newRules });
  };

  const getOperatorsForField = (field: string): string[] => {
    return STRING_FIELDS.includes(field as typeof STRING_FIELDS[number])
      ? [...STRING_OPS]
      : [...NUMERIC_OPS];
  };

  const isNumericField = (field: string): boolean => {
    return NUMERIC_FIELDS.includes(field as typeof NUMERIC_FIELDS[number]);
  };

  return (
    <div className="rule-builder" data-testid="rule-builder">
      <div className="rule-builder__header">
        <span className="rule-builder__label">Match</span>
        <select
          className="form__input rule-builder__logic"
          value={value.logic}
          onChange={(e: ChangeEvent<HTMLSelectElement>) =>
            handleLogicChange(e.target.value as 'and' | 'or')
          }
          disabled={disabled}
          aria-label="Logic operator"
        >
          <option value="and">ALL rules (AND)</option>
          <option value="or">ANY rule (OR)</option>
        </select>
      </div>

      <div className="rule-builder__rules">
        {value.rules.map((ruleOrGroup, index) => {
          // Handle nested rule groups
          if ('logic' in ruleOrGroup) {
            return (
              <div key={index} className="rule-builder__nested">
                <RuleBuilder
                  value={ruleOrGroup}
                  onChange={(group) => {
                    const newRules = [...value.rules];
                    newRules[index] = group;
                    onChange({ ...value, rules: newRules });
                  }}
                  disabled={disabled}
                />
              </div>
            );
          }

          const rule = ruleOrGroup as Rule;
          const ops = getOperatorsForField(rule.field);
          const numeric = isNumericField(rule.field);

          return (
            <div key={index} className="rule-builder__rule">
              <select
                className="form__input rule-builder__field"
                value={rule.field}
                onChange={(e: ChangeEvent<HTMLSelectElement>) =>
                  handleRuleChange(index, { ...rule, field: e.target.value })
                }
                disabled={disabled}
                aria-label="Rule field"
              >
                {RULE_FIELDS.map((f) => (
                  <option key={f} value={f}>
                    {f.replace('_', ' ')}
                  </option>
                ))}
              </select>

              <select
                className="form__input rule-builder__op"
                value={rule.op}
                onChange={(e: ChangeEvent<HTMLSelectElement>) =>
                  handleRuleChange(index, { ...rule, op: e.target.value })
                }
                disabled={disabled}
                aria-label="Rule operator"
              >
                {ops.map((op) => (
                  <option key={op} value={op}>
                    {op.replace('_', ' ')}
                  </option>
                ))}
              </select>

              <input
                className="form__input rule-builder__value"
                type={numeric ? 'number' : 'text'}
                value={rule.value}
                onChange={(e: ChangeEvent<HTMLInputElement>) =>
                  handleRuleChange(index, {
                    ...rule,
                    value: numeric ? Number(e.target.value) : e.target.value,
                  })
                }
                disabled={disabled}
                aria-label="Rule value"
              />

              <button
                type="button"
                className="rule-builder__delete"
                onClick={() => handleDeleteRule(index)}
                disabled={disabled}
                aria-label="Delete rule"
              >
                ×
              </button>
            </div>
          );
        })}
      </div>

      <button
        type="button"
        className="rule-builder__add"
        onClick={handleAddRule}
        disabled={disabled}
      >
        + Add rule
      </button>
    </div>
  );
}
